/*
  +----------------------------------------------------------------------+
  | PHP Judy                                                             |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2010 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Nicolas Brousse <nicolas@brousse.info>                       |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_judy.h"
#include "judy_handlers.h"
#include "judy_arrayaccess.h"
#include "judy_iterator.h"

ZEND_DECLARE_MODULE_GLOBALS(judy)

/* declare judy class entry */
zend_class_entry *judy_ce;

zend_object_handlers judy_handlers;

/* {{{ php_judy_init_globals
 */
static void php_judy_init_globals(zend_judy_globals *judy_globals)
{
    judy_globals->max_length = 65536;
}
 /* }}} */

/* {{{ judy_free_storage
 close all resources and the memory allocated for the object */
static void judy_object_free_storage(void *object TSRMLS_DC)
{
    judy_object *intern = (judy_object *) object;
    zend_object_std_dtor(&intern->std TSRMLS_CC);
    efree(object);
}
/* }}} */

/* {{{ judy_object_new_ex
 */
zend_object_value judy_object_new_ex(zend_class_entry *ce, judy_object **ptr TSRMLS_DC)
{
    zend_object_value retval;
    judy_object *intern;
    zval *tmp;

    intern = ecalloc(1, sizeof(judy_object));
    memset(intern, 0, sizeof(judy_object));
    if (ptr) {
        *ptr = intern;
    }

    zend_object_std_init(&(intern->std), ce TSRMLS_CC);

    zend_hash_copy(intern->std.properties, 
        &ce->default_properties, (copy_ctor_func_t) zval_add_ref,
        (void *) &tmp, sizeof(zval *));

    retval.handle = zend_objects_store_put(intern, (zend_objects_store_dtor_t) zend_objects_destroy_object, (zend_objects_free_object_storage_t) judy_object_free_storage, NULL TSRMLS_CC);
    retval.handlers = &judy_handlers;

    return retval;
}
/* }}} */

/* {{{ judy_object_new
 */
zend_object_value judy_object_new(zend_class_entry *ce TSRMLS_DC)
{
    return judy_object_new_ex(ce, NULL TSRMLS_CC);
}
/* }}} */

PHPAPI zend_class_entry *php_judy_ce(void)
{
    return judy_ce;
}

/* {{{ PHP INI entries
 */
PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("judy.string.maxlength", "65536", PHP_INI_ALL, OnUpdateLong, max_length, zend_judy_globals, judy_globals)
PHP_INI_END()
 /* }}} */

/* {{{ PHP_MINIT_FUNCTION
 */
PHP_MINIT_FUNCTION(judy)
{
    zend_class_entry ce;

    ZEND_INIT_MODULE_GLOBALS(judy, php_judy_init_globals, NULL);

    REGISTER_INI_ENTRIES();

    /* Judy class definition */

    INIT_CLASS_ENTRY(ce, "Judy", judy_class_methods);
    
    judy_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judy_ce->create_object = judy_object_new;
    
    memcpy(&judy_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    
    /* set some internal handlers */
    judy_handlers.clone_obj = judy_object_clone;
    judy_handlers.count_elements = judy_object_count;
    
    /* implements some interface to provide access to judy object as an array */
    zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_arrayaccess, zend_ce_iterator);

    judy_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    judy_ce->get_iterator = judy_get_iterator;

    REGISTER_JUDY_CLASS_CONST_LONG("BITSET", TYPE_BITSET);
    REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_INT", TYPE_INT_TO_INT);
    REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_MIXED", TYPE_INT_TO_MIXED);
    REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT", TYPE_STRING_TO_INT);
    REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED", TYPE_STRING_TO_MIXED);

    return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
*/
PHP_RINIT_FUNCTION(judy)
{
    return SUCCESS;
}

/* {{{ PHP_MSHUTDOWN_FUNCTION
 */
PHP_MSHUTDOWN_FUNCTION(judy)
{
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
 */
PHP_MINFO_FUNCTION(judy)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "Judy support", "enabled");
    php_info_print_table_row(2, "PHP Judy version", PHP_JUDY_VERSION);
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ proto Judy::__construct(long type)
 Constructs a new Judy array of the given type */
PHP_METHOD(judy, __construct)
{
    long                    type;
    judy_type               jtype;

    JUDY_METHOD_GET_OBJECT

    JUDY_METHOD_ERROR_HANDLING;

    if (intern->type) {
        php_error_docref(NULL TSRMLS_CC, E_ERROR, "Judy Array already instantiated");
    } else if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &type) == SUCCESS) {
        JTYPE(jtype, type);
        JUDY_G(counter) = 0;
        intern->type = type;
        intern->array = (Pvoid_t) NULL;
    }

    zend_restore_error_handling(&error_handling TSRMLS_CC);
}
/* }}} */

/* {{{ proto Judy::__destruct()
 Free Judy array and any other references */
PHP_METHOD(judy, __destruct)
{
    JUDY_METHOD_GET_OBJECT

    /* calling the object's free() method */
    zend_call_method_with_0_params(&object, NULL, NULL, "free", NULL);
}
/* }}} */

/* {{{ proto long Judy::free()
 Free the entire Judy Array. Return the number of bytes freed */
PHP_METHOD(judy, free)
{
    JUDY_METHOD_GET_OBJECT

    Word_t    Rc_word;
    Word_t    index;
    uint8_t   kindex[PHP_JUDY_MAX_LENGTH];
    Word_t    *PValue;

    switch (intern->type)
    {
        case TYPE_BITSET:
            /* Free Judy1 Array */
            J1FA(Rc_word, intern->array);
            break;

        case TYPE_INT_TO_INT:
            /* Free JudyL Array */
            JLFA(Rc_word, intern->array);
            break;

        case TYPE_INT_TO_MIXED:
            index = 0;

            /* Del ref to zval objects */
            JLF(PValue, intern->array, index);
            while(PValue != NULL && PValue != PJERR)
            {
                zval_ptr_dtor((zval **)PValue);
                JLN(PValue, intern->array, index);
            }
        
            /* Free JudyL Array */
            JLFA(Rc_word, intern->array);
            break;
    
        case TYPE_STRING_TO_INT:
            /* Free JudySL Array */
            JSLFA(Rc_word, intern->array);

            /* Reset counter */
            JUDY_G(counter) = 0;
            break;

        case TYPE_STRING_TO_MIXED:
            kindex[0] = '\0';

            /* Del ref to zval objects */
            JSLF(PValue, intern->array, kindex);
            while(PValue != NULL && PValue != PJERR)
            {
                zval_ptr_dtor((zval **)PValue);
                JSLN(PValue, intern->array, kindex);
            }
        
            /* Free JudySL Array */
            JSLFA(Rc_word, intern->array);

            /* Reset counter */
            JUDY_G(counter) = 0;
            break;
    }

    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long Judy::memoryUsage()
 Return the memory used by the Judy Array */
PHP_METHOD(judy, memoryUsage)
{
    Word_t     Rc_word;

    JUDY_METHOD_GET_OBJECT

    switch (intern->type)
    {
        case TYPE_BITSET:
            J1MU(Rc_word, intern->array);
            RETURN_LONG(Rc_word);
            break;
        case TYPE_INT_TO_INT:
        case TYPE_INT_TO_MIXED:
            JLMU(Rc_word, intern->array);
            RETURN_LONG(Rc_word);
            break;
        default:
            RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy::count()
 Return the current size of the array. */
PHP_METHOD(judy, count)
{
    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
                                    || intern->type == TYPE_INT_TO_MIXED) {
        Word_t   idx1 = 0;
        Word_t   idx2 = -1;
        Word_t   Rc_word;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|ll", &idx1, &idx2) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_BITSET) {
            J1C(Rc_word, intern->array, idx1, idx2);
        } else {
            JLC(Rc_word, intern->array, idx1, idx2);
        }
                
        RETURN_LONG(Rc_word);
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        RETURN_LONG(JUDY_G(counter));
    }
}
/* }}} */

/* {{{ proto long Judy::byCount(long nth_index)
 Locate the Nth index that is present in the Judy array (Nth = 1 returns the first index present).
 To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judy, byCount)
{

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
                                    || intern->type == TYPE_INT_TO_MIXED) {
        long            nth_index;
        long            index;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &nth_index) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_BITSET) {
            int Rc_int;
            J1BC(Rc_int, intern->array, nth_index, index);
            if (Rc_int == 1)
                RETURN_LONG(index);
        } else {
            PWord_t PValue;
            JLBC(PValue, intern->array, nth_index, index);
            if (PValue != NULL && PValue != PJERR)
                RETURN_LONG(index);
        }
    }
    
    RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::first([mixed index])
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy, first)
{

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t          index = 0;
        int             Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1F(Rc_int, intern->array, index);
        if (Rc_int == 1)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t          index = 0;
        PWord_t         PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLF(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        char        *str;
        int         str_length = 0;

        uint8_t     key[PHP_JUDY_MAX_LENGTH];
        PWord_t     PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &str, &str_length) == FAILURE) {
            RETURN_FALSE;
        }

        /* JudySL require null temrinated strings */
       if (str_length == 0) {
            key[0] = '\0';
        } else {
            int i;
            for (i = 0; str[i]; i++)
                key[i] = str[i];
            key[i++] = '\0';
        }

        JSLF(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR)
            RETURN_STRING(key, 1);
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::next(mixed index)
 Search (exclusive) for the next index present that is greater than the passed Index */
PHP_METHOD(judy, next)
{

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t          index;
        int             Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1N(Rc_int, intern->array, index);
        if (Rc_int == 1)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t          index;
        PWord_t         PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLN(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        char        *str;
        int         str_length;

        uint8_t     key[PHP_JUDY_MAX_LENGTH];
        PWord_t     PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &str, &str_length) == FAILURE) {
            RETURN_FALSE;
        }

        /* JudySL require null temrinated strings */
        if (str_length == 0) {
            key[0] = '\0';
        } else {
            int i;
            for (i = 0; str[i]; i++)
                key[i] = str[i];
            key[i++] = '\0';
        }

        JSLN(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR)
            RETURN_STRING(key, 1);
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::last([mixed index])
 Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judy, last)
{

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t       index = -1;
        int          Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1L(Rc_int, intern->array, index);
        if (Rc_int == 1)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t          index = -1;
        PWord_t         PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLL(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *str;
        int         str_length = 0;
    
        uint8_t     key[PHP_JUDY_MAX_LENGTH];
        PWord_t     PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &str, &str_length) == FAILURE) {
            RETURN_FALSE;
        }
    
        /* JudySL require null temrinated strings */
        if (str_length == 0) {
            unsigned int i = 0;
            for(i; i < JUDY_G(max_length); i++)
                key[i] = 0xff;
        } else {
            int i;
            for (i = 0; str[i]; i++)
                key[i] = str[i];
            key[i++] = '\0';
        }

        JSLL(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR)
            RETURN_STRING(key, 1);
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::prev(mixed index)
 Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judy, prev)
{

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t       index;
        int          Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1P(Rc_int, intern->array, index);
        if (Rc_int == 1)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t          index;
        PWord_t         PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLP(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR)
            RETURN_LONG(index);
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        char        *str;
        int         str_length;

        uint8_t     key[PHP_JUDY_MAX_LENGTH];
        PWord_t     PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &str, &str_length) == FAILURE) {
            RETURN_FALSE;
        }

        /* JudySL require null temrinated strings */
        if (str_length == 0) {
            key[0] = '\0';
        } else {
            int i;
            for (i = 0; str[i]; i++)
                key[i] = str[i];
            key[i++] = '\0';
        }

        JSLP(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR)
            RETURN_STRING(key, 1);
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto long Judy::firstEmpty([long index])
 Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judy, firstEmpty)
{
    Word_t         index = 0;
    int            Rc_int;

    JUDY_METHOD_GET_OBJECT

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    switch (intern->type)
    {
        case TYPE_BITSET:
            J1FE(Rc_int, intern->array, index);
            break;
        case TYPE_INT_TO_INT:
        case TYPE_INT_TO_MIXED:
            JLFE(Rc_int, intern->array, index);
            break;
    }

    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else { 
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy::lastEmpty([long index])
 Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judy, lastEmpty)
{
    Word_t         index = -1;
    int            Rc_int;

    JUDY_METHOD_GET_OBJECT

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    switch (intern->type)
    {
        case TYPE_BITSET:
            J1LE(Rc_int, intern->array, index);
            break;
        case TYPE_INT_TO_INT:
        case TYPE_INT_TO_MIXED:
            JLLE(Rc_int, intern->array, index);
            break;
    }

    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy::nextEmpty(long index)
 Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judy, nextEmpty)
{
    Word_t         index;
    int            Rc_int;

    JUDY_METHOD_GET_OBJECT

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    switch (intern->type)
    {
        case TYPE_BITSET:
            J1NE(Rc_int, intern->array, index);
            break;
        case TYPE_INT_TO_INT:
        case TYPE_INT_TO_MIXED:
            JLNE(Rc_int, intern->array, index);
            break;
    }

    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy::prevEmpty(long index)
   Search (exclusive) for the previous index absent that is less than the passed Index */
PHP_METHOD(judy, prevEmpty)
{
    Word_t         index;
    int            Rc_int;

    JUDY_METHOD_GET_OBJECT

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    switch (intern->type)
    {
        case TYPE_BITSET:
            J1PE(Rc_int, intern->array, index);
            break;
        case TYPE_INT_TO_INT:
        case TYPE_INT_TO_MIXED:
            JLPE(Rc_int, intern->array, index);
            break;
    }

    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto int Judy::getType()
 Return the current Judy Array type */
PHP_METHOD(judy, getType)
{
    JUDY_METHOD_GET_OBJECT
    RETURN_LONG(intern->type);
}
/* }}} */

/* {{{ proto string judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
	if (return_value_used) {
		RETURN_STRING(PHP_JUDY_VERSION, strlen(PHP_JUDY_VERSION));
	} else {
		php_printf("PHP Judy Version: %s\n", PHP_JUDY_VERSION);
	}
}
/* }}} */

/* {{{ proto int judy_type(Judy array)
   Return the php judy type for the given array */
PHP_FUNCTION(judy_type)
{
    zval *object;
    judy_object *array;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "z", &object) == FAILURE) {
        RETURN_FALSE;
    }

    array = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);
    RETURN_LONG(array->type);
}
/* }}} */

PHP_MINIT_FUNCTION(judy);
PHP_MSHUTDOWN_FUNCTION(judy);
PHP_RINIT_FUNCTION(judy);
PHP_MINFO_FUNCTION(judy);

/* PHP Judy Function */
PHP_FUNCTION(judy_version);
PHP_FUNCTION(judy_type);

/* {{{ PHP Judy Methods
 */
PHP_METHOD(judy, __construct);
PHP_METHOD(judy, __destruct);
PHP_METHOD(judy, getType);
PHP_METHOD(judy, free);
PHP_METHOD(judy, memoryUsage);
PHP_METHOD(judy, count);
PHP_METHOD(judy, byCount);
PHP_METHOD(judy, first);
PHP_METHOD(judy, next);
PHP_METHOD(judy, last);
PHP_METHOD(judy, prev);
PHP_METHOD(judy, firstEmpty);
PHP_METHOD(judy, nextEmpty);
PHP_METHOD(judy, lastEmpty);
PHP_METHOD(judy, prevEmpty);
/* }}} */

/* {{{ PHP Judy Methods for the Array Access Interface
 */
PHP_METHOD(judy, offsetSet);
PHP_METHOD(judy, offsetUnset);
PHP_METHOD(judy, offsetGet);
PHP_METHOD(judy, offsetExists);
/* }}} */

/* {{{ Judy function parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_type, 0, 0, 1)
    ZEND_ARG_INFO(0, array)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_count, 0, 0, 0)
    ZEND_ARG_INFO(0, index_start)
    ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_byCount, 0, 0, 1)
    ZEND_ARG_INFO(0, nth_index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_first, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_next, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_last, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prev, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_firstEmpty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_nextEmpty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_lastEmpty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prevEmpty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters the Array Access Interface
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetSet, 0, 0, 2)
    ZEND_ARG_INFO(0, offset)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetUnset, 0, 0, 1)
    ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetGet, 0, 0, 1)
    ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetExists, 0, 0, 1)
    ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ judy_functions[]
 *
 * Every user visible function must have an entry in judy_functions[].
 */
const zend_function_entry judy_functions[] = {
	/* PHP JUDY FUNCTIONS */
    PHP_FE(judy_version, NULL)
    PHP_FE(judy_type, arginfo_judy_type)

    /* NULL TEMRINATED VECTOR */
    {NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy_class_methods[].
 */
const zend_function_entry judy_class_methods[] = {
	/* PHP JUDY METHODS */
    PHP_ME(judy, __construct, 		NULL, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(judy, __destruct, 		NULL, ZEND_ACC_PUBLIC | ZEND_ACC_DTOR)
    PHP_ME(judy, getType, 			NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, free, 				NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, memoryUsage, 		NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, count, 			arginfo_judy_count, ZEND_ACC_PUBLIC)
    PHP_ME(judy, byCount, 			arginfo_judy_byCount, ZEND_ACC_PUBLIC)
    PHP_ME(judy, first, 			arginfo_judy_first, ZEND_ACC_PUBLIC)
    PHP_ME(judy, next, 				arginfo_judy_next, ZEND_ACC_PUBLIC)
    PHP_ME(judy, last, 				arginfo_judy_last, ZEND_ACC_PUBLIC)
    PHP_ME(judy, prev, 				arginfo_judy_prev, ZEND_ACC_PUBLIC)
    PHP_ME(judy, firstEmpty, 		arginfo_judy_firstEmpty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, nextEmpty, 		arginfo_judy_nextEmpty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, lastEmpty, 		arginfo_judy_lastEmpty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, prevEmpty, 		arginfo_judy_prevEmpty, ZEND_ACC_PUBLIC)

    /* PHP JUDY METHODS / ARRAYACCESS INTERFACE */
    PHP_ME(judy, offsetSet, 		arginfo_judy_offsetSet, ZEND_ACC_PUBLIC)
    PHP_ME(judy, offsetUnset, 		arginfo_judy_offsetUnset, ZEND_ACC_PUBLIC)
    PHP_ME(judy, offsetGet, 		arginfo_judy_offsetGet, ZEND_ACC_PUBLIC)
    PHP_ME(judy, offsetExists, 		arginfo_judy_offsetExists, ZEND_ACC_PUBLIC)

    /* PHP JUDY METHODS ALIAS */
    PHP_MALIAS(judy, size, 			count, NULL, ZEND_ACC_PUBLIC)

    /* NULL TEMRINATED VECTOR */
    {NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy_module_entry
 */
zend_module_entry judy_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
    STANDARD_MODULE_HEADER,
#endif
    PHP_JUDY_EXTNAME,
    judy_functions,
    PHP_MINIT(judy),
    PHP_MSHUTDOWN(judy),
    PHP_RINIT(judy),
    NULL,
    PHP_MINFO(judy),
#if ZEND_MODULE_API_NO >= 20010901
    PHP_JUDY_VERSION,
#endif
    STANDARD_MODULE_PROPERTIES
};
/* }}} */

#ifdef COMPILE_DL_JUDY
ZEND_GET_MODULE(judy)
#endif

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

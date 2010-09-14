/*
  +----------------------------------------------------------------------+
  | PHP Version 5                                                        |
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

#include "php.h"
#include "php_ini.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/info.h"

#ifndef PHP_JUDY_H
#include "php_judy.h"
#endif

/* {{{ php_judy_init_globals
 */
static void php_judy_init_globals(zend_judy_globals *judy_globals)
{
    judy_globals->max_length = 65536;
}
 /* }}} */

/* {{{ judy_object_free_array
 free judy array */
static Word_t judy_object_free_array(judy_object *object TSRMLS_DC)
{
    Word_t    Rc_word;
    Word_t    index;
    uint8_t   kindex[JUDY_G(max_length)];           // Key/index
    Word_t    *PValue;                              // Pointer to the value

    switch (object->type)
    {
        case TYPE_BITSET:
            // Free Judy Array
            J1FA(Rc_word, object->array);
            break;

        case TYPE_INT_TO_INT:
            // Free Judy Array
            JLFA(Rc_word, object->array);
            break;

        case TYPE_INT_TO_MIXED:
            index = 0;

            // Del ref to zval objects
            JLF(PValue, object->array, index);
            while(PValue != NULL && PValue != PJERR)
            {
                zval_ptr_dtor((zval **)PValue);
                JLN(PValue, object->array, index);
            }
        
            // Free Judy Array
            JLFA(Rc_word, object->array);
            break;
    
        case TYPE_STRING_TO_INT:
            // Free Judy Array
            JSLFA(Rc_word, object->array);

            // Reset counter
            JUDY_G(counter) = 0;
            break;

        case TYPE_STRING_TO_MIXED:
            kindex[0] = '\0';

            // Del ref to zval objects
            JSLF(PValue, object->array, kindex);
            while(PValue != NULL && PValue != PJERR)
            {
                zval_ptr_dtor((zval **)PValue);
                JSLN(PValue, object->array, kindex);
            }
        
            // Free Judy Array
            JSLFA(Rc_word, object->array);

            // Reset counter
            JUDY_G(counter) = 0;
            break;
    }

    return Rc_word;
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

zend_class_entry *judy_ce;

PHPAPI zend_class_entry *php_judy_ce(void)
{
    return judy_ce;
}

/* {{{ judy_object_count
 */
int judy_object_count(zval *object, long *count TSRMLS_DC)
{
	zval *rv;
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);
    
    /* calling the object's count() method */
	zend_call_method_with_0_params(&object, NULL, NULL, "count", &rv);
	*count = Z_LVAL_P(rv);
	
	/* destruct the zval returned value */
	zval_ptr_dtor(&rv);
	
	return SUCCESS;
}
/* }}} */

/* {{{ judy_object_clone
 */
zend_object_value judy_object_clone(zval *this_ptr TSRMLS_DC)
{
    judy_object *new_obj = NULL;
    judy_object *old_obj = (judy_object *) zend_object_store_get_object(this_ptr TSRMLS_CC);
    zend_object_value new_ov = judy_object_new_ex(old_obj->std.ce, &new_obj TSRMLS_CC);

    zend_objects_clone_members(&new_obj->std, new_ov, &old_obj->std, Z_OBJ_HANDLE_P(this_ptr) TSRMLS_CC);

    Pvoid_t newJArray = (Pvoid_t) NULL; // new Judy array to populate

    if (old_obj->type == TYPE_BITSET) {
        /* Cloning Judy1 Array */

        Word_t  kindex = 0; // Key/index
        int     Rc_int = 0; // Insert return value

        J1F(Rc_int, old_obj->array, kindex);
        while (Rc_int == 1)
        {
            J1S(Rc_int, newJArray, kindex);
            J1N(Rc_int, newJArray, kindex); 
        }
    } else if (old_obj->type == TYPE_INT_TO_INT || old_obj->type == TYPE_INT_TO_MIXED) {
        /* Cloning JudyL Array */

        Word_t kindex = 0; // Key/index
        Word_t *PValue; // Pointer to the old value
        Word_t *newPValue; // Pointer to the new value

        JLF(PValue, old_obj->array, kindex);
        while(PValue != NULL && PValue != PJERR)
        {
            JLI(newPValue, newJArray, kindex);
            if (newPValue != NULL && newPValue != PJERR) {
                *newPValue = *PValue;
                if (old_obj->type == TYPE_INT_TO_MIXED)
                    Z_ADDREF_P(*(zval **)PValue);
            }
            JLN(PValue, old_obj->array, kindex)
        }
    } else if (old_obj->type == TYPE_STRING_TO_INT || old_obj->type == TYPE_STRING_TO_MIXED) {
        /* Cloning JudySL Array */

        uint8_t kindex[JUDY_G(max_length)]; // Key/index
        Word_t *PValue; // Pointer to the old value
        Word_t *newPValue; // Pointer to the new value
    
        /* smallest string is a null-terminated character */
        kindex[0] = '\0';

        JSLF(PValue, old_obj->array, kindex);
        while(PValue != NULL && PValue != PJERR)
        {
            JSLI(newPValue, newJArray, kindex);
            if (newPValue != NULL && newPValue != PJERR) {
                *newPValue = *PValue;
                if (old_obj->type == TYPE_STRING_TO_MIXED)
                    Z_ADDREF_P(*(zval **)PValue);
            }
            JSLN(PValue, old_obj->array, kindex)
        }
    }

    new_obj->array = newJArray;
    new_obj->type = old_obj->type;

    return new_ov; 
}
/* }}} */

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

    REGISTER_LONG_CONSTANT("JUDY_BITSET", TYPE_BITSET, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_INT_TO_INT", TYPE_INT_TO_INT, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_INT_TO_MIXED", TYPE_INT_TO_MIXED, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_STRING_TO_INT", TYPE_STRING_TO_INT, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_STRING_TO_MIXED", TYPE_STRING_TO_MIXED, CONST_PERSISTENT | CONST_CS);

    /* Judy */

    INIT_CLASS_ENTRY(ce, "Judy", judy_class_methods);
    judy_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judy_ce->create_object = judy_object_new;
    memcpy(&judy_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judy_handlers.clone_obj = judy_object_clone;
    judy_handlers.count_elements = judy_object_count;
    /* zend_classImplements(judy_ce TSRMLS_CC, 1, zend_ce_arrayaccess); 
    zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judy_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

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

    JUDY_METHOD_ERROR_HANDLING;

    JUDY_METHOD_GET_OBJECT;

	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &type) == SUCCESS) {
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

    JUDY_METHOD_GET_OBJECT;
    
    /* free judy array */
    judy_object_free_array(intern TSRMLS_CC);
}
/* }}} */

/* {{{ proto long Judy::free()
 Free the entire Judy Array. Return the number of bytes freed */
PHP_METHOD(judy, free)
{
    JUDY_METHOD_GET_OBJECT;

    /* free judy array */
    RETURN_LONG(judy_object_free_array(intern TSRMLS_CC));
}
/* }}} */

/* {{{ proto long Judy::memory_usage()
 Return the memory used by the Judy Array */
PHP_METHOD(judy, memory_usage)
{
    Word_t     Rc_word;

    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto boolean Judy::set(mixed index, [mixed value])
 Set the current index */
PHP_METHOD(judy, set)
{
    JUDY_METHOD_GET_OBJECT;

    if (intern->type == TYPE_BITSET)
    {
        Word_t      index;
        int         Rc_int;
   
        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1S(Rc_int, intern->array, index);
        RETURN_BOOL(Rc_int);
    } else if (intern->type == TYPE_INT_TO_INT) {
        Word_t      index;
        Word_t      value;
        Word_t      *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ll", &index, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JLI(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            *PValue = value;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }  
    } else if (intern->type == TYPE_INT_TO_MIXED) {
        Word_t      index;
        zval        *value;
        Pvoid_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "lz", &index, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JLI(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            *(zval **)PValue = value;
            Z_ADDREF_P(*(zval **)PValue);
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    } else if (intern->type == TYPE_STRING_TO_INT) {
        uint8_t     *key;
        int         key_length;
        Word_t      *value;
        PWord_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &key, &key_length, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JSLI(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            *PValue = value;
            JUDY_G(counter)++;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    } else if (intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;
        zval        *value;
        Pvoid_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sz", &key, &key_length, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JSLI(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            *(zval **)PValue = value;
            Z_ADDREF_P(*(zval **)PValue);
            JUDY_G(counter)++;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    }
}
/* }}} */

/* {{{ proto boolean Judy::unset(mixed index)
 Remove the index from the Judy array */
PHP_METHOD(judy, unset)
{
    int         Rc_int;

    JUDY_METHOD_GET_OBJECT;

    if (intern->type == TYPE_BITSET) {
        Word_t      index;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1U(Rc_int, intern->array, index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t      index;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_INT_TO_INT) {
            JLD(Rc_int, intern->array, index);
        } else {
            Pvoid_t     *PValue;
            JLG(PValue, intern->array, index);
            if (PValue != NULL && PValue != PJERR) {
                zval_ptr_dtor((zval **)PValue);
                JLD(Rc_int, intern->array, index);
            }
        }
        if (Rc_int == 1)
            JUDY_G(counter)--;
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_STRING_TO_INT) {
            JSLD(Rc_int, intern->array, key);
        } else {
            Pvoid_t     *PValue;
            JSLG(PValue, intern->array, key);
            if (PValue != NULL && PValue != PJERR) {
                zval_ptr_dtor((zval **)PValue);
                JSLD(Rc_int, intern->array, key);
            }
        }
        if (Rc_int == 1)
            JUDY_G(counter)--;
    }
    
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto mixed Judy::get(mixed key)
 Return the value of a given index (true/false for a bitset) */
PHP_METHOD(judy, get)
{

    JUDY_METHOD_GET_OBJECT;

    if (intern->type == TYPE_BITSET) {
        Word_t  index;
        int     Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1T(Rc_int, intern->array, index);
        RETURN_BOOL(Rc_int);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t    index;
        Word_t    *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLG(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            if (intern->type == TYPE_INT_TO_INT) {
                RETURN_LONG(*PValue);
            } else {
                RETURN_ZVAL(*((zval **)PValue), 1, 0);
            }
        }
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;
        Word_t      *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
            RETURN_FALSE;
        }

        JSLG(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            if (intern->type == TYPE_STRING_TO_INT) {
                RETURN_LONG(*PValue);
            } else {
                RETURN_ZVAL(*((zval **)PValue), 1 ,0);
            }
        }
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto long Judy::count()
 Return the current size of the array. */
PHP_METHOD(judy, count)
{
    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto long Judy::by_count(long nth_index)
 Locate the Nth index that is present in the Judy array (Nth = 1 returns the first index present).
 To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judy, by_count)
{

    JUDY_METHOD_GET_OBJECT;

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

    JUDY_METHOD_GET_OBJECT;

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

        uint8_t     key[JUDY_G(max_length)];
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

    JUDY_METHOD_GET_OBJECT;

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

        uint8_t     key[JUDY_G(max_length)];
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

    JUDY_METHOD_GET_OBJECT;

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
    
        uint8_t     key[JUDY_G(max_length)];
        int         key_length;
        PWord_t     PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &str, &str_length) == FAILURE) {
            RETURN_FALSE;
        }
    
        /* JudySL require null temrinated strings */
        if (str_length == 0) {
            int i = 0;
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

    JUDY_METHOD_GET_OBJECT;

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

        uint8_t     key[JUDY_G(max_length)];
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

/* {{{ proto long Judy::first_empty([long index])
 Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judy, first_empty)
{
    Word_t         index = 0;
    int            Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto long Judy::last_empty([long index])
 Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judy, last_empty)
{
    Word_t         index = -1;
    int            Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto long Judy::next_empty(long index)
 Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judy, next_empty)
{
    Word_t         index;
    int            Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto long Judy::prev_empty(long index)
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy, prev_empty)
{
    Word_t         index;
    int            Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

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

/* {{{ proto void judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
   php_printf("PHP Judy Version: %s\n", PHP_JUDY_VERSION);
}
/* }}} */


PHP_MINIT_FUNCTION(judy);
PHP_MSHUTDOWN_FUNCTION(judy);
PHP_RINIT_FUNCTION(judy);
PHP_MINFO_FUNCTION(judy);

/* PHP Judy Function */
PHP_FUNCTION(judy_version);

/* PHP Judy Class */
PHP_METHOD(judy, __construct);
PHP_METHOD(judy, __destruct);
PHP_METHOD(judy, free);
PHP_METHOD(judy, memory_usage);
PHP_METHOD(judy, set);
PHP_METHOD(judy, unset);
PHP_METHOD(judy, get);
PHP_METHOD(judy, count);
PHP_METHOD(judy, by_count);
PHP_METHOD(judy, first);
PHP_METHOD(judy, next);
PHP_METHOD(judy, last);
PHP_METHOD(judy, prev);
PHP_METHOD(judy, first_empty);
PHP_METHOD(judy, next_empty);
PHP_METHOD(judy, last_empty);
PHP_METHOD(judy, prev_empty);

/* {{{ Judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_set, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_unset, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_get, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_count, 0, 0, 0)
    ZEND_ARG_INFO(0, index_start)
    ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_by_count, 0, 0, 1)
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_first_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_next_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_last_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prev_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}}} */

/* {{{ judy_functions[]
 *
 * Every user visible function must have an entry in judy_functions[].
 */
const zend_function_entry judy_functions[] = {
	PHP_FE(judy_version, NULL)
	{NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy_class_methods[].
 */
const zend_function_entry judy_class_methods[] = {
    PHP_ME(judy, __construct, NULL, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(judy, __destruct, NULL, ZEND_ACC_PUBLIC | ZEND_ACC_DTOR)
    PHP_ME(judy, free, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, memory_usage, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, set, arginfo_judy_set, ZEND_ACC_PUBLIC)
    PHP_ME(judy, unset, arginfo_judy_unset, ZEND_ACC_PUBLIC)
    PHP_ME(judy, get, arginfo_judy_get, ZEND_ACC_PUBLIC)
    PHP_ME(judy, count, arginfo_judy_count, ZEND_ACC_PUBLIC)
    PHP_ME(judy, by_count, arginfo_judy_by_count, ZEND_ACC_PUBLIC)
    PHP_ME(judy, first, arginfo_judy_first, ZEND_ACC_PUBLIC)
    PHP_ME(judy, next, arginfo_judy_next, ZEND_ACC_PUBLIC)
    PHP_ME(judy, last, arginfo_judy_last, ZEND_ACC_PUBLIC)
    PHP_ME(judy, prev, arginfo_judy_prev, ZEND_ACC_PUBLIC)
    PHP_ME(judy, first_empty, arginfo_judy_first_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, next_empty, arginfo_judy_next_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, last_empty, arginfo_judy_last_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy, prev_empty, arginfo_judy_prev_empty, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judy, size, count, NULL, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judy, insert, set, NULL, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judy, remove, unset, NULL, ZEND_ACC_PUBLIC)
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

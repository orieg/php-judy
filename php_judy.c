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
#include "zend_interfaces.h"
#include "ext/standard/info.h"

#ifndef PHP_JUDY_H
#include "php_judy.h"
#endif

#ifndef PHP_JUDY1_H
#include "lib/judy1.h"
#endif

#ifndef PHP_JUDYL_H
#include "lib/judyl.h"
#endif

#ifndef PHP_JUDYSL_H
#include "lib/judysl.h"
#endif

#ifndef PHP_JUDYHS_H
#include "lib/judyhs.h"
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
Word_t judy_object_free_array(judy_object *object TSRMLS_DC)
{
    Word_t    Rc_word;
    uint8_t   kindex[JUDY_G(max_length)];           // Key/index
    Word_t    *PValue;                              // Pointer to the value
    switch (object->type)
    {
        case TYPE_JUDYSL:

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

/* {{{ judy_object_destroy_object
 free Judy array and any other references */
static void judy_object_destroy_object(zend_object *object, zend_object_handle handle TSRMLS_DC)
{
    judy_object *intern = (judy_object *) object;
    
    /* free judy array */
    judy_object_free_array(intern TSRMLS_CC);

    /* call standard dtor */
    zend_objects_destroy_object(object, handle TSRMLS_CC);
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

    retval.handle = zend_objects_store_put(intern, (zend_objects_store_dtor_t) judy_object_destroy_object, (zend_objects_free_object_storage_t) judy_object_free_storage, NULL TSRMLS_CC);
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

/* {{{ judy_object_clone
 */
zend_object_value judy_object_clone(zval *this_ptr TSRMLS_DC)
{
    /* TODO clone Judy */
}
/* }}} */

/* {{{ judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy___construct, 0, 0, 1)
    ZEND_ARG_INFO(0, type)
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
    PHP_ME(judy, __construct, arginfo_judy___construct, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
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

    REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDY1", TYPE_JUDY1, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYL", TYPE_JUDYL, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYSL", TYPE_JUDYSL, CONST_PERSISTENT | CONST_CS);
    REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYHS", TYPE_JUDYHS, CONST_PERSISTENT | CONST_CS);

    /* Judy */

    INIT_CLASS_ENTRY(ce, "Judy", judy_class_methods);
    judy_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judy_ce->create_object = judy_object_new;
    memcpy(&judy_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judy_handlers.clone_obj = judy_object_clone;
    /* zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judy_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

    /**
     * Judy1
     *
     * Judy1 Class for creating and accessing a dynamic array of bits,
     * using any value of a word as an index. 
     */

    INIT_CLASS_ENTRY(ce, "Judy1", judy1_class_methods);
    judy1_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judy1_ce->create_object = judy_object_new;
    memcpy(&judy1_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judy1_handlers.clone_obj = judy1_object_clone;
    /* zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judy1_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

    REGISTER_JUDY_CLASS_CONST_LONG("TYPE_JUDY1", TYPE_JUDY1);

    /**
     * JudyL
     *
     * JudyL Class for creating and accessing a dynamic array of words,
     * using a word as an index.
     */

    INIT_CLASS_ENTRY(ce, "JudyL", judyl_class_methods);
    judyl_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judyl_ce->create_object = judy_object_new;
    memcpy(&judyl_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judyl_handlers.clone_obj = judyl_object_clone;
    /* zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judyl_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

    REGISTER_JUDY_CLASS_CONST_LONG("TYPE_JUDYL", TYPE_JUDYL);

    /**
     * JudySL
     *
     * JudySL Class for creating and accessing a dynamic array, using a
     * null-terminated string as an Index (associative array).
     */

    INIT_CLASS_ENTRY(ce, "JudySL", judysl_class_methods);
    judysl_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judysl_ce->create_object = judy_object_new;
    memcpy(&judysl_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judysl_handlers.clone_obj = judysl_object_clone;
    /* zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judysl_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

    REGISTER_JUDY_CLASS_CONST_LONG("TYPE_JUDYSL", TYPE_JUDYSL);

    /**
     * JudyHS
     *
     * JudyHS Class for creating and accessing a dynamic array, using an array-of-bytes
     * of Length as an Index and a word as a Value. 
     */

    INIT_CLASS_ENTRY(ce, "JudyHS", judyhs_class_methods);
    judyhs_ce = zend_register_internal_class_ex(&ce, NULL, NULL TSRMLS_CC);
    judyhs_ce->create_object = judy_object_new;
    memcpy(&judyhs_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judyhs_handlers.clone_obj = judyhs_object_clone;
    /* zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator); */
    judyhs_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    /* judy_ce->get_iterator = judy_get_iterator; */

    REGISTER_JUDY_CLASS_CONST_LONG("TYPE_JUDYHS", TYPE_JUDYHS);

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
    judy_object             *intern;

    JUDY_METHOD_ERROR_HANDLING;

	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &type) == SUCCESS) {
        JTYPE(jtype, type);
        if (jtype == TYPE_JUDY1) {
            intern = (judy_object*) zend_object_store_get_object(getThis() TSRMLS_CC);
            intern->type = TYPE_JUDY1;
        }
        intern->array = (Pvoid_t) NULL;
	}
    zend_restore_error_handling(&error_handling TSRMLS_CC);
}
/* }}} */

/* {{{ proto void judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
   php_printf("PHP Judy Version: %s\n", PHP_JUDY_VERSION);
}
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

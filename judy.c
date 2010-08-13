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
#include "ext/standard/info.h"
#include "php_judy.h"

/* {{{ judy_functions[]
 *
 * Every user visible function must have an entry in judy_functions[].
 */
const zend_function_entry judy_functions[] = {
	PHP_FE(judy_version, NULL)
	{NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO(arginfo_judy___construct, 0)
    ZEND_ARG_INFO(0, type)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO(arginfo_judy_set, 0)
    ZEND_ARG_INFO(0, index)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO(arginfo_judy_get, 0)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}}} */

/* {{{ judy_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy_class_methods[].
 */
const zend_function_entry judy_class_methods[] = {
    PHP_ME(judy, __construct, arginfo_judy___construct, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
    PHP_ME(judy, set, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, get, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, count, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy, memory_usage, NULL, ZEND_ACC_PUBLIC)
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

/* declare judy class handlers */
static zend_object_handlers judy_handlers;

/* declare judy class entry */
static zend_class_entry *judy_ce;

/* {{{ judy_free_storage
 close all resources and the memory allocated for the object */
static void judy_object_free_storage(void *object TSRMLS_DC)
{
    judy_object *intern = (judy_object *) object;

    zend_object_std_dtor(&intern->std TSRMLS_CC);
    
    zend_object_std_dtor(&(intern->std) TSRMLS_CC);

    if (intern->array) {
        efree(intern->array);
    }

    efree(object);
}
/* }}} */

/* {{{ judy_object_new
 */
static zend_object_value judy_object_new(zend_class_entry *ce TSRMLS_DC)
{
    zend_object_value retval;
    judy_object *intern;

    intern = emalloc(sizeof(judy_object));
    memset(intern, 0, sizeof(judy_object));
    zend_object_std_init(&(intern->std), ce TSRMLS_CC);

    zend_hash_copy(intern->std.properties, 
        &ce->default_properties, (copy_ctor_func_t) zval_add_ref,
        NULL, sizeof(zval *));

    retval.handle = zend_objects_store_put(intern, (zend_objects_store_dtor_t) zend_objects_destroy_object, (zend_objects_free_object_storage_t) judy_object_free_storage, NULL TSRMLS_CC);
    retval.handlers = &judy_handlers;
    return retval;
}
/* }}} */

/* {{{ PHP_MINIT_FUNCTION
 */
PHP_MINIT_FUNCTION(judy)
{

	REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDY1", TYPE_JUDY1, CONST_PERSISTENT | CONST_CS);
	REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYL", TYPE_JUDYL, CONST_PERSISTENT | CONST_CS);
	REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYSL", TYPE_JUDYSL, CONST_PERSISTENT | CONST_CS);
	REGISTER_LONG_CONSTANT("JUDY_TYPE_JUDYHS", TYPE_JUDYHS, CONST_PERSISTENT | CONST_CS);

	//REGISTER_INI_ENTRIES();

    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "judy", judy_class_methods);
    judy_ce = zend_register_internal_class(&ce TSRMLS_CC);
    judy_ce->create_object = judy_object_new;
    memcpy(&judy_handlers, zend_get_std_object_handlers(),
        sizeof(zend_object_handlers));
    judy_handlers.clone_obj = NULL;
    //zend_class_implements(judy_ce TSRMLS_CC, 1, zend_ce_iterator);
    judy_ce->ce_flags |= ZEND_ACC_FINAL_CLASS;
    //judy_ce->get_iterator = judy_get_iterator;

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
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
 */
PHP_MINFO_FUNCTION(judy)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "judy support", "enabled");
	php_info_print_table_end();
}
/* }}} */

/* {{{ proto void judy::__construct(long type)
 Constructs a new Judy array of the given type */
PHP_METHOD(judy, __construct)
{
    judy_object *intern;
    long        type;
    judy_type  jtype;
    zend_error_handling error_handling;

    zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
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

/* {{{ proto boolean judy::set(long key[, bool value])
 Set the current key */
PHP_METHOD(judy, set)
{
    long        index;
    zend_bool   value = 1;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l|b", &index, &value) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    if (intern->type == TYPE_JUDY1) {
        if (value) {
            J1S(Rc_int, intern->array, index);
        } else {
            J1U(Rc_int, intern->array, index);
        }
        RETURN_BOOL(Rc_int);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto boolean judy::get(long key)
 Get the value of the current key */
PHP_METHOD(judy, get)
{
    long    index;
    int     Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    if (intern->type == TYPE_JUDY1) {
        J1T(Rc_int, intern->array, index);
        RETURN_BOOL(Rc_int);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto boolean judy::count([long idx1[, long idx2]])
 Count the number of indexespresent in the array between idx1 and idx2 (inclusive) */
PHP_METHOD(judy, count)
{
    long            idx1 = 0;
    long            idx2 = -1;
    unsigned long   Rc_word;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|ll", &idx1, &idx2) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    if (intern->type == TYPE_JUDY1) {
        J1C(Rc_word, intern->array, idx1, idx2);
        RETURN_LONG(Rc_word);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto boolean judy::get(long key)
 Get the value of the current key */
PHP_METHOD(judy, memory_usage)
{
    unsigned long     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    if (intern->type == TYPE_JUDY1) {
        J1MU(Rc_word, intern->array);
        RETURN_LONG(Rc_word);
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

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

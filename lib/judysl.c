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

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"

#ifndef PHP_JUDYSL_H
#include "lib/judysl.h"
#endif

/* {{{ judysl_class_methodss[]
 *
 * Every user visible JudySL method must have an entry in judysl_class_methods[].
 */
const zend_function_entry judysl_class_methods[] = {
    PHP_ME(judysl, __construct, NULL, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
    PHP_ME(judysl, free, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, size, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, insert, arginfo_judysl_insert, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, remove, arginfo_judysl_remove, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, get, arginfo_judysl_get, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, first, arginfo_judysl_first, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, next, arginfo_judysl_next, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, last, arginfo_judysl_last, ZEND_ACC_PUBLIC)
    PHP_ME(judysl, prev, arginfo_judysl_prev, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judysl, ins, insert, NULL, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judysl, del, remove, NULL, ZEND_ACC_PUBLIC)
	{NULL, NULL, NULL}
};
/* }}} */

/* declare judysl class entry */
zend_class_entry *judysl_ce;

PHPAPI zend_class_entry *php_judysl_ce(void)
{
    return judysl_ce;
}

/* {{{ judysl_object_clone */
zend_object_value judysl_object_clone(zval *this_ptr TSRMLS_DC)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = (judy_object *) zend_object_store_get_object(this_ptr TSRMLS_CC);
	zend_object_value new_ov = judy_object_new_ex(old_obj->std.ce, &new_obj TSRMLS_CC);
	
	zend_objects_clone_members(&new_obj->std, new_ov, &old_obj->std, Z_OBJ_HANDLE_P(this_ptr) TSRMLS_CC);
	
    Pvoid_t   newJArray = (Pvoid_t) NULL;           // new JudySL array to populate
    uint8_t   kindex[JUDY_G(max_length)];           // Key/index
    Word_t    *PValue;                              // Pointer to the old value
    Word_t    *newPValue;                           // Pointer to the new value
    
    strcpy(kindex, "");

    JSLF(PValue, &old_obj->array, kindex);
    while(PValue != NULL && PValue != PJERR)
    {
        JSLN(PValue, &old_obj->array, kindex)
        JSLI(newPValue, newJArray, kindex);
        if (newPValue != NULL && newPValue != PJERR)
            *newPValue = *PValue;
    }

    new_obj->array = newJArray;
    new_obj->type = TYPE_JUDYSL;
	return new_ov;
}
/* }}} */

/* {{{ proto void JudySL::__construct(long type)
 Constructs a new JudySL array of the given type */
PHP_METHOD(judysl, __construct)
{
    judy_object *intern;
    zend_error_handling error_handling;

    zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
    intern = (judy_object*) zend_object_store_get_object(getThis() TSRMLS_CC);
    intern->type = TYPE_JUDYSL;
    intern->array = (Pvoid_t) NULL;
    zend_restore_error_handling(&error_handling TSRMLS_CC);

    JUDY_G(counter) = 0;
}
/* }}} */

/* {{{ proto long JudySL::free()
 Free the entire JudySL Array. Return the number of bytes freed */
PHP_METHOD(judysl, free)
{
    Word_t     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLFA(Rc_word, intern->array);
    JUDY_G(counter) = 0;
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long JudySL::size()
 Return the current size of the array. */
PHP_METHOD(judysl, size)
{
    RETURN_LONG(JUDY_G(counter));
}
/* }}} */

/* {{{ proto boolean JudySL::insert(string key, long value)
 Set the current index */
PHP_METHOD(judysl, insert)
{
    uint8_t     *key;
    int         key_length;
    long        value;
    Word_t      *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &key, &key_length, &value) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLI(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        *PValue = (Word_t) value;
        JUDY_G(counter)++;
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}
/* }}} */

/* {{{ proto boolean JudySL::remove(string key)
 Remove the index from the JudySL array */
PHP_METHOD(judysl, remove)
{
    uint8_t     *key;
    int         key_length;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLD(Rc_int, intern->array, key);
    if (Rc_int == 1)
        JUDY_G(counter)--;
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto long JudySL::get(string key)
 Get the value of a given index */
PHP_METHOD(judysl, get)
{
    uint8_t     *key;
    int         key_length;
    Word_t      *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLG(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(*PValue);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto string JudySL::first([string key])
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judysl, first)
{
    char        *str;
    int         str_length;

    uint8_t     key[JUDY_G(max_length)];
    PWord_t     PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &str, &str_length) == FAILURE) {
        RETURN_FALSE;
    }

    if (str_length == 0) {
        key[0] = '\0';
    } else {
        int i;
        for (i = 0; str[i]; i++)
            key[i] = str[i];
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLF(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_STRING(key, 1);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto string JudySL::next(string key)
 Search (exclusive) for the next index present that is greater than the passed Index */
PHP_METHOD(judysl, next)
{
    uint8_t     *key;
    int         key_length;
    PWord_t     PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLN(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_STRING(key, 1);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto string JudySL::last([string key])
 Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judysl, last)
{
    uint8_t     *str;
    int         str_length;
    
    uint8_t     key[JUDY_G(max_length)];
    PWord_t     PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|s", &str, &str_length) == FAILURE) {
        RETURN_FALSE;
    }
    
    if (str_length == 0) {
        int i = 0;
        for(i; i < JUDY_G(max_length); i++)
            key[i] = 0xff;
    } else {
        int i;
        for (i = 0; str[i]; i++)
            key[i] = str[i];
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLL(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_STRING(key, 1);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto string JudySL::prev(string key)
 Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judysl, prev)
{
    char        *key;
    int         key_length;
    PWord_t     PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JSLP(PValue, intern->array, key);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_STRING(key, 1);
    } else {
        RETURN_NULL();
    }
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

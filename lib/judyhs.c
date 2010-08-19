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

#ifndef PHP_JUDYHS_H
#include "lib/judyhs.h"
#endif

/* {{{ judyhs_class_methodss[]
 *
 * Every user visible JudyHS method must have an entry in judyhs_class_methods[].
 */
const zend_function_entry judyhs_class_methods[] = {
    PHP_ME(judyhs, __construct, NULL, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
    PHP_ME(judyhs, free, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judyhs, size, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judyhs, insert, arginfo_judyhs_insert, ZEND_ACC_PUBLIC)
    PHP_ME(judyhs, remove, arginfo_judyhs_remove, ZEND_ACC_PUBLIC)
    PHP_ME(judyhs, get, arginfo_judyhs_get, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judyhs, ins, insert, NULL, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judyhs, del, remove, NULL, ZEND_ACC_PUBLIC)
	{NULL, NULL, NULL}
};
/* }}} */

/* declare judyhs class entry */
zend_class_entry *judyhs_ce;

PHPAPI zend_class_entry *php_judyhs_ce(void)
{
    return judyhs_ce;
}

/* {{{ judyhs_object_clone */
zend_object_value judyhs_object_clone(zval *this_ptr TSRMLS_DC)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = (judy_object *) zend_object_store_get_object(this_ptr TSRMLS_CC);
	zend_object_value new_ov = judy_object_new_ex(old_obj->std.ce, &new_obj TSRMLS_CC);
	
	zend_objects_clone_members(&new_obj->std, new_ov, &old_obj->std, Z_OBJ_HANDLE_P(this_ptr) TSRMLS_CC);
	
    Pvoid_t   newJArray = (Pvoid_t) NULL;           // new JudyHS array to populate
    uint8_t   kindex[JUDY_G(max_length)];           // Key/index
    Word_t    *PValue;                              // Pointer to the old value
    Word_t    *newPValue;                           // Pointer to the new value
    
    strcpy(kindex, "");

    JHSF(PValue, &old_obj->array, kindex, strlen(kindex));
    while(PValue != NULL && PValue != PJERR)
    {
        int kindex_length = strlen(kindex);
        JHSN(PValue, &old_obj->array, kindex, kindex_length);
        JHSI(newPValue, newJArray, kindex, kindex_length);
        if (newPValue != NULL && newPValue != PJERR)
            *newPValue = *PValue;
    }

    new_obj->array = newJArray;
    new_obj->type = TYPE_JUDYHS;
	return new_ov;
}
/* }}} */

/* {{{ proto void JudyHS::__construct(long type)
 Constructs a new JudyHS array of the given type */
PHP_METHOD(judyhs, __construct)
{
    
    JUDY_METHOD_ERROR_HANDLING;

    JUDY_METHOD_GET_OBJECT;

    intern = (judy_object*) zend_object_store_get_object(getThis() TSRMLS_CC);
    intern->type = TYPE_JUDYHS;
    intern->array = (Pvoid_t) NULL;
    zend_restore_error_handling(&error_handling TSRMLS_CC);

    JUDY_G(counter) = 0;
}
/* }}} */

/* {{{ proto long JudyHS::free()
 Free the entire JudyHS Array. Return the number of bytes freed */
PHP_METHOD(judyhs, free)
{
    Word_t     Rc_word;

    JUDY_METHOD_GET_OBJECT;

    JHSFA(Rc_word, intern->array);
    JUDY_G(counter) = 0;
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long JudyHS::size()
 Return the current size of the array. */
PHP_METHOD(judyhs, size)
{
    RETURN_LONG(JUDY_G(counter));
}
/* }}} */

/* {{{ proto boolean JudyHS::insert(string key, long value)
 Set the current index */
PHP_METHOD(judyhs, insert)
{
    uint8_t     *key;
    int         key_length;
    long        value;
    Word_t      *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &key, &key_length, &value) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

    JHSI(PValue, intern->array, key, key_length);
    if (PValue != NULL && PValue != PJERR) {
        *PValue = (Word_t) value;
        JUDY_G(counter)++;
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}
/* }}} */

/* {{{ proto boolean JudyHS::remove(string key)
 Remove the index from the JudyHS array */
PHP_METHOD(judyhs, remove)
{
    uint8_t     *key;
    int         key_length;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

    JHSD(Rc_int, intern->array, key, key_length);
    if (Rc_int == 1)
        JUDY_G(counter)--;
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto long JudyHS::get(string key)
 Get the value of a given index */
PHP_METHOD(judyhs, get)
{
    uint8_t     *key;
    int         key_length;
    Word_t      *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
        RETURN_FALSE;
    }

    JUDY_METHOD_GET_OBJECT;

    JHSG(PValue, intern->array, key, key_length);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(*PValue);
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

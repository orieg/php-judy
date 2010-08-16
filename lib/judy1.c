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

#ifndef PHP_JUDY1_H
#include "lib/judy1.h"
#endif

/* {{{ judy1_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy1_class_methods[].
 */
const zend_function_entry judy1_class_methods[] = {
    PHP_ME(judy1, __construct, NULL, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
    PHP_ME(judy1, free, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, memory_usage, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, set, arginfo_judy1_set, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, unset, arginfo_judy1_unset, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, test, arginfo_judy1_test, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, count, arginfo_judy1_count, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, by_count, arginfo_judy1_by_count, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, first, arginfo_judy1_first, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, next, arginfo_judy1_next, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, last, arginfo_judy1_last, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, prev, arginfo_judy1_prev, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, first_empty, arginfo_judy1_first_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, next_empty, arginfo_judy1_next_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, last_empty, arginfo_judy1_last_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judy1, prev_empty, arginfo_judy1_prev_empty, ZEND_ACC_PUBLIC)
	{NULL, NULL, NULL}
};
/* }}} */

/* declare judy1 class entry */
zend_class_entry *judy1_ce;

PHPAPI zend_class_entry *php_judy1_ce(void)
{
    return judy1_ce;
}

/* {{{ judy1_object_clone */
zend_object_value judy1_object_clone(zval *this_ptr TSRMLS_DC)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = (judy_object *) zend_object_store_get_object(this_ptr TSRMLS_CC);
	zend_object_value new_ov = judy_object_new_ex(old_obj->std.ce, &new_obj TSRMLS_CC);
	
	zend_objects_clone_members(&new_obj->std, new_ov, &old_obj->std, Z_OBJ_HANDLE_P(this_ptr) TSRMLS_CC);
	
    Pvoid_t   newJArray = 0;            // new Judy1 array to populate
    Word_t    kindex;                   // Key/index
    int       Ins_rv = 0;               // Insert return value

    for (kindex = 0L, Ins_rv = Judy1First(&old_obj->array, &kindex, PJE0);
         Ins_rv == 1; Ins_rv = Judy1Next(&old_obj->array, &kindex, PJE0))
    {
        Ins_rv = Judy1Set(&newJArray, kindex, PJE0);
    }

    new_obj->array = newJArray;
    new_obj->type = TYPE_JUDY1;

	return new_ov;
}
/* }}} */

/* {{{ proto void Judy1::__construct(long type)
 Constructs a new Judy1 array of the given type */
PHP_METHOD(judy1, __construct)
{
    judy_object *intern;
    zend_error_handling error_handling;

    zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
    intern = (judy_object*) zend_object_store_get_object(getThis() TSRMLS_CC);
    intern->type = TYPE_JUDY1;
    intern->array = (Pvoid_t) NULL;
    zend_restore_error_handling(&error_handling TSRMLS_CC);
}
/* }}} */

/* {{{ proto long Judy1::free()
 Free the entire Judy1 Array. Return the number of bytes freed */
PHP_METHOD(judy1, free)
{
    Word_t     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1FA(Rc_word, intern->array);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long Judy1::memory_usage()
 Return the memory used by the Judy1 Array */
PHP_METHOD(judy1, memory_usage)
{
    Word_t     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1MU(Rc_word, intern->array);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto boolean Judy1::set(long index)
 Set the current index */
PHP_METHOD(judy1, set)
{
    long        index;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1S(Rc_int, intern->array, index);
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto boolean Judy1::unset(long index)
 Remove the index from the Judy1 array */
PHP_METHOD(judy1, unset)
{
    long        index;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1U(Rc_int, intern->array, index);
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto boolean Judy1::test(long key)
 Test if index is set */
PHP_METHOD(judy1, test)
{
    long    index;
    int     Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1T(Rc_int, intern->array, index);
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto boolean Judy1::count([long index_start[, long index_end]])
 Count the number of indexes present in the array between index_start and index_end (inclusive) */
PHP_METHOD(judy1, count)
{
    long            idx1 = 0;
    long            idx2 = -1;
    unsigned long   Rc_word;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|ll", &idx1, &idx2) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1C(Rc_word, intern->array, idx1, idx2);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long Judy1::by_count(long nth_index)
 Locate the Nth index that is present in the Judy1 array (Nth = 1 returns the first index present).
 To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judy1, by_count)
{
    long            nth_index;
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &nth_index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1BC(Rc_int, intern->array, nth_index, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::first([long index])
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy1, first)
{
    long            index = 0;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1F(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::next(long index)
 Search (exclusive) for the next index present that is greater than the passed Index */
PHP_METHOD(judy1, next)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1N(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::last([long index])
 Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judy1, last)
{
    long            index = -1;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1L(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::prev(long index)
 Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judy1, prev)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1P(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::first_empty([long index])
 Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judy1, first_empty)
{
    long            index = 0;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1FE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::next_empty(long index)
 Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judy1, next_empty)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1NE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::last_empty([long index])
 Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judy1, last_empty)
{
    long            index = -1;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1LE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long Judy1::prev_empty(long index)
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy1, prev_empty)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    J1PE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
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

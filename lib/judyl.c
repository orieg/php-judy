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

#ifndef PHP_JUDYL_H
#include "lib/judyl.h"
#endif

/* {{{ judyl_class_methodss[]
 *
 * Every user visible JudyL method must have an entry in judyl_class_methods[].
 */
const zend_function_entry judyl_class_methods[] = {
    PHP_ME(judyl, __construct, NULL, ZEND_ACC_CTOR | ZEND_ACC_PUBLIC)
    PHP_ME(judyl, free, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, memory_usage, NULL, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, insert, arginfo_judyl_insert, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, remove, arginfo_judyl_remove, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, get, arginfo_judyl_get, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, count, arginfo_judyl_count, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, by_count, arginfo_judyl_by_count, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, first, arginfo_judyl_first, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, next, arginfo_judyl_next, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, last, arginfo_judyl_last, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, prev, arginfo_judyl_prev, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, first_empty, arginfo_judyl_first_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, next_empty, arginfo_judyl_next_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, last_empty, arginfo_judyl_last_empty, ZEND_ACC_PUBLIC)
    PHP_ME(judyl, prev_empty, arginfo_judyl_prev_empty, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judyl, ins, insert, NULL, ZEND_ACC_PUBLIC)
    PHP_MALIAS(judyl, del, remove, NULL, ZEND_ACC_PUBLIC)
	{NULL, NULL, NULL}
};
/* }}} */

/* declare judyl class entry */
zend_class_entry *judyl_ce;

PHPAPI zend_class_entry *php_judyl_ce(void)
{
    return judyl_ce;
}

/* {{{ judyl_object_clone */
zend_object_value judyl_object_clone(zval *this_ptr TSRMLS_DC)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = (judy_object *) zend_object_store_get_object(this_ptr TSRMLS_CC);
	zend_object_value new_ov = judy_object_new_ex(old_obj->std.ce, &new_obj TSRMLS_CC);
	
	zend_objects_clone_members(&new_obj->std, new_ov, &old_obj->std, Z_OBJ_HANDLE_P(this_ptr) TSRMLS_CC);
	
    Pvoid_t   newJArray = (Pvoid_t) NULL;            // new JudyL array to populate
    Word_t    kindex;                   // Key/index
    Word_t    *PValue;                   // Pointer to the old value
    Word_t    *newPValue;                // Pointer to the new value

    JLF(PValue, &old_obj->array, kindex);
    while(PValue != NULL && PValue != PJERR)
    {
        JLN(PValue, &old_obj->array, kindex)
        JLI(newPValue, newJArray, kindex);
        if (newPValue != NULL && newPValue != PJERR)
            *newPValue = *PValue;
    }

    new_obj->array = newJArray;
    new_obj->type = TYPE_JUDYL;
	return new_ov;
}
/* }}} */

/* {{{ proto void JudyL::__construct(long type)
 Constructs a new JudyL array of the given type */
PHP_METHOD(judyl, __construct)
{
    judy_object *intern;
    zend_error_handling error_handling;

    zend_replace_error_handling(EH_THROW, NULL, &error_handling TSRMLS_CC);
    intern = (judy_object*) zend_object_store_get_object(getThis() TSRMLS_CC);
    intern->type = TYPE_JUDYL;
    intern->array = (Pvoid_t) NULL;
    zend_restore_error_handling(&error_handling TSRMLS_CC);
}
/* }}} */

/* {{{ proto long JudyL::free()
 Free the entire JudyL Array. Return the number of bytes freed */
PHP_METHOD(judyl, free)
{
    Word_t     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLFA(Rc_word, intern->array);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long JudyL::memory_usage()
 Return the memory used by the JudyL Array */
PHP_METHOD(judyl, memory_usage)
{
    Word_t     Rc_word;

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLMU(Rc_word, intern->array);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto boolean JudyL::insert(long index, long value)
 Set the current index */
PHP_METHOD(judyl, insert)
{
    long        index;
    long        value;
    Word_t      *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ll", &index, &value) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLI(PValue, intern->array, (Word_t) index);
    if (PValue != NULL && PValue != PJERR) {
        *PValue = (Word_t) value;
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}
/* }}} */

/* {{{ proto boolean JudyL::remove(long index)
 Remove the index from the JudyL array */
PHP_METHOD(judyl, remove)
{
    long        index;
    int         Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLD(Rc_int, intern->array, index);
    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto long JudyL::get(long index)
 Get the value of a given index */
PHP_METHOD(judyl, get)
{
    long    index;
    Word_t  *PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLG(PValue, intern->array, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(*PValue);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto boolean JudyL::count([long index_start[, long index_end]])
 Count the number of indexes present in the array between index_start and index_end (inclusive) */
PHP_METHOD(judyl, count)
{
    long            idx1 = 0;
    long            idx2 = -1;
    unsigned long   Rc_word;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|ll", &idx1, &idx2) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLC(Rc_word, intern->array, idx1, idx2);
    RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long JudyL::by_count(long nth_index)
 Locate the Nth index that is present in the JudyL array (Nth = 1 returns the first index present).
 To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judyl, by_count)
{
    long            nth_index;
    long            index;
    PWord_t         PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &nth_index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLBC(PValue, intern->array, nth_index, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::first([long index])
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judyl, first)
{
    long            index = 0;
    PWord_t         PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLF(PValue, intern->array, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::next(long index)
 Search (exclusive) for the next index present that is greater than the passed Index */
PHP_METHOD(judyl, next)
{
    long            index;
    PWord_t         PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLN(PValue, intern->array, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::last([long index])
 Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judyl, last)
{
    long            index = -1;
    PWord_t         PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLL(PValue, intern->array, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::prev(long index)
 Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judyl, prev)
{
    long            index;
    PWord_t         PValue;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLP(PValue, intern->array, index);
    if (PValue != NULL && PValue != PJERR) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::first_empty([long index])
 Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judyl, first_empty)
{
    long            index = 0;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLFE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::next_empty(long index)
 Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judyl, next_empty)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLNE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::last_empty([long index])
 Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judyl, last_empty)
{
    long            index = -1;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "|l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLLE(Rc_int, intern->array, index);
    if (Rc_int == 1) {
        RETURN_LONG(index);
    } else {
        RETURN_NULL();
    }
}
/* }}} */

/* {{{ proto long JudyL::prev_empty(long index)
 Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judyl, prev_empty)
{
    long            index;
    int             Rc_int;

    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
        RETURN_FALSE;
    }

    zval *object = getThis();
    judy_object *intern = (judy_object *) zend_object_store_get_object(object TSRMLS_CC);

    JLPE(Rc_int, intern->array, index);
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

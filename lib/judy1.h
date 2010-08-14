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

#ifndef PHP_JUDY1_H
#define PHP_JUDY1_H

#include <Judy.h>

#ifndef PHP_JUDY_H
#include "php_judy.h"
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

/* PHP Judy1 Class */
PHP_METHOD(judy1, __construct);
PHP_METHOD(judy1, free);
PHP_METHOD(judy1, memory_usage);
PHP_METHOD(judy1, set);
PHP_METHOD(judy1, unset);
PHP_METHOD(judy1, test);
PHP_METHOD(judy1, count);
PHP_METHOD(judy1, by_count);
PHP_METHOD(judy1, first);
PHP_METHOD(judy1, next);
PHP_METHOD(judy1, last);
PHP_METHOD(judy1, prev);
PHP_METHOD(judy1, first_empty);
PHP_METHOD(judy1, next_empty);
PHP_METHOD(judy1, last_empty);
PHP_METHOD(judy1, prev_empty);

void judy1_object_free_storage(void * TSRMLS_DC);

/* {{{ judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_set, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_unset, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_test, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_count, 0, 0, 0)
    ZEND_ARG_INFO(0, index_start)
    ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_by_count, 0, 0, 1)
    ZEND_ARG_INFO(0, nth_index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_first, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_next, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_last, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_prev, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_first_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_next_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_last_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy1_prev_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}}} */

extern const zend_function_entry judy1_class_methods[];

/* declare judy class entry */
zend_class_entry *judy1_ce;

zend_object_handlers judy1_handlers;
zend_object_value judy1_object_clone(zval *this_ptr TSRMLS_DC);

/* Grabbing CE's so that other exts can use the date objects too */
PHPAPI zend_class_entry *php_judy1_ce(void);

#endif	/* PHP_JUDY_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

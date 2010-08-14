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

#ifndef PHP_JUDYL_H
#define PHP_JUDYL_H

#include <Judy.h>

#ifndef PHP_JUDY_H
#include "php_judy.h"
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

/* PHP JudyL Class */
PHP_METHOD(judyl, __construct);
PHP_METHOD(judyl, free);
PHP_METHOD(judyl, memory_usage);
PHP_METHOD(judyl, insert);
PHP_METHOD(judyl, remove);
PHP_METHOD(judyl, get);
PHP_METHOD(judyl, count);
PHP_METHOD(judyl, by_count);
PHP_METHOD(judyl, first);
PHP_METHOD(judyl, next);
PHP_METHOD(judyl, last);
PHP_METHOD(judyl, prev);
PHP_METHOD(judyl, first_empty);
PHP_METHOD(judyl, next_empty);
PHP_METHOD(judyl, last_empty);
PHP_METHOD(judyl, prev_empty);

//PHP_MALIAS(judyl, ins, insert, arg_info, flags);
//PHP_MALIAS(judyl, del, remove, arg_info, flags);

void judyl_object_free_storage(void * TSRMLS_DC);

/* {{{ judy class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_insert, 0, 0, 2)
    ZEND_ARG_INFO(0, index)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_remove, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_get, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_count, 0, 0, 0)
    ZEND_ARG_INFO(0, index_start)
    ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_by_count, 0, 0, 1)
    ZEND_ARG_INFO(0, nth_index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_first, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_next, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_last, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_prev, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_first_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_next_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_last_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judyl_prev_empty, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}}} */

extern const zend_function_entry judyl_class_methods[];

/* declare judy class entry */
zend_class_entry *judyl_ce;

zend_object_handlers judyl_handlers;
zend_object_value judyl_object_clone(zval *this_ptr TSRMLS_DC);

/* Grabbing CE's so that other exts can use the JudyL object too */
PHPAPI zend_class_entry *php_judyl_ce(void);

#endif	/* PHP_JUDY_H */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

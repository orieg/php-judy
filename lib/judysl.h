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

#ifndef PHP_JUDYSL_H
#define PHP_JUDYSL_H

#include <Judy.h>

#ifndef PHP_JUDY_H
#include "php_judy.h"
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

/* PHP JudySL Class */
PHP_METHOD(judysl, __construct);
PHP_METHOD(judysl, free);
PHP_METHOD(judysl, size);
PHP_METHOD(judysl, insert);
PHP_METHOD(judysl, remove);
PHP_METHOD(judysl, get);
PHP_METHOD(judysl, first);
PHP_METHOD(judysl, next);
PHP_METHOD(judysl, last);
PHP_METHOD(judysl, prev);

void judysl_object_free_storage(void * TSRMLS_DC);

/* {{{ JudySL class methods parameters
 */
ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_insert, 0, 0, 2)
    ZEND_ARG_INFO(0, index)
    ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_remove, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_get, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_first, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_next, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_last, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judysl_prev, 0, 0, 1)
    ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}}} */

extern const zend_function_entry judysl_class_methods[];

/* declare judy class entry */
zend_class_entry *judysl_ce;

zend_object_handlers judysl_handlers;
zend_object_value judysl_object_clone(zval *this_ptr TSRMLS_DC);

/* Grabbing CE's so that other exts can use the JudyL object too */
PHPAPI zend_class_entry *php_judysl_ce(void);

#endif	/* PHP_JUDYSL_H */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

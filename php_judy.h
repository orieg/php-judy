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

#ifndef PHP_JUDY_H
#define PHP_JUDY_H

#define PHP_JUDY_VERSION "0.1"
#define PHP_JUDY_EXTNAME "judy"

#include <Judy.h>

extern zend_module_entry judy_module_entry;
#define phpext_judy_ptr &judy_module_entry

#ifdef PHP_WIN32
#	define PHP_JUDY_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#	define PHP_JUDY_API __attribute__ ((visibility("default")))
#else
#	define PHP_JUDY_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

PHP_MINIT_FUNCTION(judy);
PHP_MSHUTDOWN_FUNCTION(judy);
PHP_RINIT_FUNCTION(judy);
PHP_MINFO_FUNCTION(judy);

/* PHP Judy Function */
PHP_FUNCTION(judy_version);

/* PHP Judy Class */
PHP_METHOD(judy, __construct);

typedef enum _judy_type {
    TYPE_JUDY1=1,
    TYPE_JUDYL,
    TYPE_JUDYSL,
    TYPE_JUDYHS
} judy_type;

#define JTYPE(jtype, type) { \
    if (type != TYPE_JUDY1 && type != TYPE_JUDYL \
                           && type != TYPE_JUDYSL \
                           && type != TYPE_JUDYHS) { \
        php_error_docref(NULL TSRMLS_CC, E_WARNING, "Type must be JUDY_TYPE_JUDY1 or JUDY_TYPE_JUDYL or JUDY_TYPE_JUDYSL or JUDY_TYPE_JUDYHS"); \
    } \
    jtype = type; \
}

typedef struct _judy_object {
    zend_object     std;
    long            type;
    Pvoid_t         array;
} judy_object;

static void judy_object_free_storage(void * TSRMLS_DC);

/* declare judy class entry */
zend_class_entry *judy_ce;

zend_object_handlers judy_handlers;
zend_object_value judy_object_new(zend_class_entry *ce);
zend_object_value judy_object_new_ex(zend_class_entry *ce, judy_object **ptr TSRMLS_DC);
zend_object_value judy_object_clone(zval *this_ptr TSRMLS_DC);

/* {{{ REGISTER_JUDY_CLASS_CONST_LONG */
#define REGISTER_JUDY_CLASS_CONST_LONG(const_name, value) \
    zend_declare_class_constant_long(judy_ce, const_name, sizeof(const_name)-1, (long) value TSRMLS_CC);
/* }}} */

ZEND_BEGIN_MODULE_GLOBALS(judy)
    long    max_length;
    long    counter;
ZEND_END_MODULE_GLOBALS(judy)

ZEND_DECLARE_MODULE_GLOBALS(judy)

#ifdef ZTS
#define JUDY_G(v) TSRMG(judy_globals_id, zend_judy_globals *, v)
#else
#define JUDY_G(v) (judy_globals.v)
#endif

/* Grabbing CE's so that other exts can use the date objects too */
PHPAPI zend_class_entry *php_judy_ce(void);

#endif	/* PHP_JUDY_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

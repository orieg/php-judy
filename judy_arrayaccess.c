/*
  +----------------------------------------------------------------------+
  | PHP Judy                                                             |
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

#include "php_judy.h"
#include "judy_arrayaccess.h"

/* {{{ proto int Judy::offsetSet(mixed offset, mixed value)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetSet)
{
    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET)
    {
        zval        *zindex;
        Word_t		index;
        zend_bool	value;
        int         Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "z!b", &zindex, &value) == FAILURE) {
            RETURN_FALSE;
        }

        if (!zindex){
        	index = -1;
    		J1L(Rc_int, intern->array, index);
    		if (Rc_int == 1) {
    			index += 1;
			} else {
				RETURN_FALSE;
			}
        } else {
        	index = Z_LVAL_P(zindex);
        }

        if (value == 1) {
        	J1S(Rc_int, intern->array, index);
        } else {
        	J1U(Rc_int, intern->array, index);
        }
        RETURN_BOOL(Rc_int);
    } else if (intern->type == TYPE_INT_TO_INT) {
    	zval        *zindex;
        Word_t      index;
        Word_t      value;
        Word_t      *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "z!l", &zindex, &value) == FAILURE) {
            RETURN_FALSE;
        }

        if (!zindex){
        	index = -1;
    		JLL(PValue, intern->array, index);
            if (PValue != NULL && PValue != PJERR) {
    			index += 1;
			} else {
				RETURN_FALSE;
			}
        } else {
        	index = Z_LVAL_P(zindex);
        }

        JLI(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            *PValue = value;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    } else if (intern->type == TYPE_INT_TO_MIXED) {
    	zval        *zindex;
        Word_t      index;
        zval        *value;
        Pvoid_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "z!z", &zindex, &value) == FAILURE) {
            RETURN_FALSE;
        }

        if (!zindex){
        	index = -1;
    		JLL(PValue, intern->array, index);
            if (PValue != NULL && PValue != PJERR) {
    			index += 1;
			} else {
				RETURN_FALSE;
			}
        } else {
        	index = Z_LVAL_P(zindex);
        }

        JLI(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            *(zval **)PValue = value;
            Z_ADDREF_P(*(zval **)PValue);
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    } else if (intern->type == TYPE_STRING_TO_INT) {
        uint8_t     *key;
        int         key_length;
        Word_t      *value;
        PWord_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sl", &key, &key_length, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JSLI(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            *PValue = value;
            JUDY_G(counter)++;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    } else if (intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;
        zval        *value;
        Pvoid_t     *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sz", &key, &key_length, &value) == FAILURE) {
            RETURN_FALSE;
        }

        JSLI(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            *(zval **)PValue = value;
            Z_ADDREF_P(*(zval **)PValue);
            JUDY_G(counter)++;
            RETURN_TRUE;
        } else {
            RETURN_FALSE;
        }
    }
    RETURN_FALSE;
}
/* }}} */

/* {{{ proto int Judy::offsetSet(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetUnset)
{
    int         Rc_int;

    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t      index;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1U(Rc_int, intern->array, index);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t      index;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_INT_TO_INT) {
            JLD(Rc_int, intern->array, index);
        } else {
            Pvoid_t     *PValue;
            JLG(PValue, intern->array, index);
            if (PValue != NULL && PValue != PJERR) {
                zval_ptr_dtor((zval **)PValue);
                JLD(Rc_int, intern->array, index);
            }
        }
        if (Rc_int == 1)
            JUDY_G(counter)--;
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
            RETURN_FALSE;
        }

        if (intern->type == TYPE_STRING_TO_INT) {
            JSLD(Rc_int, intern->array, key);
        } else {
            Pvoid_t     *PValue;
            JSLG(PValue, intern->array, key);
            if (PValue != NULL && PValue != PJERR) {
                zval_ptr_dtor((zval **)PValue);
                JSLD(Rc_int, intern->array, key);
            }
        }
        if (Rc_int == 1)
            JUDY_G(counter)--;
    }

    RETURN_BOOL(Rc_int);
}
/* }}} */

/* {{{ proto mixed Judy::offsetGet(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetGet)
{
    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t  index;
        int     Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1T(Rc_int, intern->array, index);
        RETURN_BOOL(Rc_int);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t    index;
        Word_t    *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLG(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            if (intern->type == TYPE_INT_TO_INT) {
                RETURN_LONG(*PValue);
            } else {
                RETURN_ZVAL(*((zval **)PValue), 1, 0);
            }
        }
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;
        Word_t      *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
            RETURN_FALSE;
        }

        JSLG(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            if (intern->type == TYPE_STRING_TO_INT) {
                RETURN_LONG(*PValue);
            } else {
                RETURN_ZVAL(*((zval **)PValue), 1 ,0);
            }
        }
    }

    RETURN_NULL();
}
/* }}} */

/* {{{ proto int Judy::offsetExists(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetExists)
{
    JUDY_METHOD_GET_OBJECT

    if (intern->type == TYPE_BITSET) {
        Word_t  index;
        int     Rc_int;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        J1T(Rc_int, intern->array, index);
        RETURN_BOOL(Rc_int);
    } else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
        Word_t    index;
        Word_t    *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &index) == FAILURE) {
            RETURN_FALSE;
        }

        JLG(PValue, intern->array, index);
        if (PValue != NULL && PValue != PJERR) {
            RETURN_TRUE;
        }
    } else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
        uint8_t     *key;
        int         key_length;
        Word_t      *PValue;

        if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &key, &key_length) == FAILURE) {
            RETURN_FALSE;
        }

        JSLG(PValue, intern->array, key);
        if (PValue != NULL && PValue != PJERR) {
            RETURN_TRUE;
        }
    }

    RETURN_FALSE;
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

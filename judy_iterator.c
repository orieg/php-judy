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
#include "judy_iterator.h"

/* {{{ judy iterator handlers table
*/
zend_object_iterator_funcs judy_iterator_funcs = {
	judy_iterator_dtor,
	judy_iterator_valid,
	judy_iterator_current_data,
	judy_iterator_current_key,
	judy_iterator_move_forward,
	judy_iterator_rewind,
	NULL /* invalidate current */
};
/* }}} */

/* {{{ judy_get_iterator
*/
zend_object_iterator *judy_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
{
	judy_iterator *it;

	if (by_ref) {
		zend_throw_error(NULL, "An iterator cannot be used with foreach by reference");
		return NULL;
	}

	it = emalloc(sizeof(judy_iterator));

	Z_ADDREF_P(object);

	ZVAL_COPY_VALUE(&it->intern.data, object);

	zend_iterator_init((zend_object_iterator*)it);
	it->intern.funcs = &judy_iterator_funcs;
	ZVAL_UNDEF(&it->key);
	ZVAL_UNDEF(&it->data);
	it->valid = 0;
	it->key_scratch = emalloc(PHP_JUDY_MAX_LENGTH);

	return &it->intern;
}
/* }}} */

/* {{{ judy_iterator_data_dtor
*/
void judy_iterator_data_dtor(judy_iterator *it)
{
	zval_ptr_dtor(&it->key);
	ZVAL_UNDEF(&it->key);
	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);
	it->valid = 0;
}
/* }}} */

/* {{{ judy_iterator_dtor
*/
void judy_iterator_dtor(zend_object_iterator *iterator)
{
	judy_iterator   *it = (judy_iterator*) iterator;

	judy_iterator_data_dtor(it);
	efree(it->key_scratch);

	zval_ptr_dtor(&it->intern.data);
}
/* }}} */

/* {{{ judy_iterator_valid
*/
int judy_iterator_valid(zend_object_iterator *iterator)
{
	judy_iterator *it = (judy_iterator *) iterator;
	return it->valid ? SUCCESS : FAILURE;
}
/* }}} */

/* {{{ judy_iterator_current_data
*/
zval *judy_iterator_current_data(zend_object_iterator *iterator)
{
	judy_iterator *it = (judy_iterator*) iterator;
	return &it->data;
}
/* }}} */

/* {{{ judy_iterator_current_key
*/
void judy_iterator_current_key(zend_object_iterator *iterator, zval *key)
{
	judy_iterator 	*it = (judy_iterator*) iterator;

	ZVAL_ZVAL(key, &it->key, 1, 0);
}
/* }}} */

/* {{{ judy_iterator_move_forward
*/
void judy_iterator_move_forward(zend_object_iterator *iterator)
{
	JUDY_ITERATOR_GET_OBJECT

	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);

	if (object->type == TYPE_BITSET) {

		Word_t          index;
		int             Rc_int;

		if (Z_ISUNDEF_P(&it->key)) {
			index = 0;
			J1F(Rc_int, object->array, index);
		} else {
			index = Z_LVAL_P(&it->key);
			J1N(Rc_int, object->array, index);
		}

		if (Rc_int) {
			zval_ptr_dtor(&it->key);
			ZVAL_LONG(&it->key, index);
			ZVAL_BOOL(&it->data, 1);
			it->valid = 1;
		} else {
			judy_iterator_data_dtor(it);
		}

	} else if (object->type == TYPE_INT_TO_INT || object->type == TYPE_INT_TO_MIXED
			|| object->type == TYPE_INT_TO_PACKED) {

		Word_t          index;
		Pvoid_t          *PValue = NULL;

		if (Z_ISUNDEF_P(&it->key)) {
			index = 0;
			JLF(PValue, object->array, index);
		} else {
			index = Z_LVAL_P(&it->key);
			JLN(PValue, object->array, index);
		}

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&it->key);
			ZVAL_LONG(&it->key, index);

			if (object->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&it->data, JUDY_LVAL_READ(PValue));
			} else if (object->type == TYPE_INT_TO_PACKED) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &it->data);
				} else {
					ZVAL_NULL(&it->data);
				}
			} else {
				zval *value = JUDY_MVAL_READ(PValue);

				ZVAL_COPY(&it->data, value);
			}
			it->valid = 1;
		} else {
			judy_iterator_data_dtor(it);
		}

	} else if (object->type == TYPE_STRING_TO_INT || object->type == TYPE_STRING_TO_MIXED) {

		uint8_t     *key = it->key_scratch;
		Pvoid_t      *PValue;

		/* JudySL require null terminated strings */
		if (Z_TYPE_P(&it->key) == IS_STRING) {
			size_t copy_len = Z_STRLEN_P(&it->key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&it->key);
			memcpy(key, Z_STRVAL_P(&it->key), copy_len);
			key[copy_len] = '\0';

			JSLN(PValue, object->array, key);
		} else {
			key[0] = '\0';
			JSLF(PValue, object->array, key);
		}

		if ((PValue != NULL && PValue != PJERR)) {
			size_t new_len = strlen((char *)key);
			if (Z_TYPE(it->key) == IS_STRING && !ZSTR_IS_INTERNED(Z_STR(it->key))
				&& GC_REFCOUNT(Z_STR(it->key)) == 1 && new_len <= ZSTR_LEN(Z_STR(it->key))) {
				memcpy(ZSTR_VAL(Z_STR(it->key)), key, new_len + 1);
				ZSTR_LEN(Z_STR(it->key)) = new_len;
			} else {
				zval_ptr_dtor(&it->key);
				ZVAL_STRINGL(&it->key, (char *)key, new_len);
			}

			if (object->type == TYPE_STRING_TO_INT) {
				ZVAL_LONG(&it->data, JUDY_LVAL_READ(PValue));
			} else {
				zval *value = JUDY_MVAL_READ(PValue);

				ZVAL_COPY(&it->data, value);
			}
			it->valid = 1;
		} else {
			judy_iterator_data_dtor(it);
		}
	} else if (object->type == TYPE_STRING_TO_MIXED_HASH
			|| object->type == TYPE_STRING_TO_INT_HASH
			|| object->type == TYPE_STRING_TO_MIXED_ADAPTIVE
			|| object->type == TYPE_STRING_TO_INT_ADAPTIVE) {

		uint8_t      *key = it->key_scratch;
		Pvoid_t      *KValue;

		/* Navigate key_index (JudySL) for ordering */
		if (Z_TYPE_P(&it->key) == IS_STRING) {
			size_t key_len;
			key_len = Z_STRLEN_P(&it->key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&it->key);
			memcpy(key, Z_STRVAL_P(&it->key), key_len);
			key[key_len] = '\0';

			JSLN(KValue, object->key_index, key);
		} else {
			key[0] = '\0';
			JSLF(KValue, object->key_index, key);
		}

		if (KValue != NULL && KValue != PJERR) {
			size_t new_len = strlen((char *)key);
			/* 2D: Reuse zend_string buffer if possible */
			if (Z_TYPE(it->key) == IS_STRING && !ZSTR_IS_INTERNED(Z_STR(it->key))
				&& GC_REFCOUNT(Z_STR(it->key)) == 1
				&& new_len <= ZSTR_LEN(Z_STR(it->key))) {
				memcpy(ZSTR_VAL(Z_STR(it->key)), key, new_len + 1);
				ZSTR_LEN(Z_STR(it->key)) = new_len;
			} else {
				zval_ptr_dtor(&it->key);
				ZVAL_STRINGL(&it->key, (char *)key, new_len);
			}

			Pvoid_t *VValue = NULL;
			if (object->type == TYPE_STRING_TO_MIXED_ADAPTIVE || object->type == TYPE_STRING_TO_INT_ADAPTIVE) {
				Word_t sso_idx;
				if (judy_pack_short_string_internal((char *)key, new_len, &sso_idx)) {
					JLG(VValue, object->array, sso_idx);
				} else {
					JHSG(VValue, object->hs_array, key, (Word_t)new_len);
				}
			} else {
				JHSG(VValue, object->array, key, (Word_t)new_len);
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				if (object->type == TYPE_STRING_TO_INT_HASH || object->type == TYPE_STRING_TO_INT_ADAPTIVE) {
					ZVAL_LONG(&it->data, JUDY_LVAL_READ(VValue));
				} else {
					zval *value = JUDY_MVAL_READ(VValue);
					ZVAL_COPY(&it->data, value);
				}
			} else {
				ZVAL_NULL(&it->data);
			}
			it->valid = 1;
		} else {
			judy_iterator_data_dtor(it);
		}
	}
}
/* }}} */

/* {{{ judy_iterator_rewind
*/
void judy_iterator_rewind(zend_object_iterator *iterator)
{
	JUDY_ITERATOR_GET_OBJECT

	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);

	if (object->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		J1F(Rc_int, object->array, index);
		if (Rc_int) {
			zval_ptr_dtor(&it->key);
			ZVAL_LONG(&it->key, index);
			ZVAL_BOOL(&it->data, 1);
			it->valid = 1;
		} else {
			judy_iterator_data_dtor(it);
		}

	} else if (object->type == TYPE_INT_TO_INT || object->type == TYPE_INT_TO_MIXED
			|| object->type == TYPE_INT_TO_PACKED) {

		Word_t          index   = 0;
		Pvoid_t          *PValue = NULL;

		JLF(PValue, object->array, index);
		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&it->key);
			ZVAL_LONG(&it->key, index);

			if (object->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&it->data, JUDY_LVAL_READ(PValue));
			} else if (object->type == TYPE_INT_TO_PACKED) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &it->data);
				} else {
					ZVAL_NULL(&it->data);
				}
			} else {
				zval *value = JUDY_MVAL_READ(PValue);

				ZVAL_COPY(&it->data, value);
			}
			it->valid = 1;
		}

	} else if (object->type == TYPE_STRING_TO_INT || object->type == TYPE_STRING_TO_MIXED) {

		uint8_t     *key = it->key_scratch;
		Pvoid_t      *PValue;

		/* JudySL require null terminated strings */
		key[0] = '\0';
		JSLF(PValue, object->array, key);

		if (PValue != NULL && PValue != PJERR) {
			size_t new_len = strlen((char *)key);
			zval_ptr_dtor(&it->key);
			ZVAL_STRINGL(&it->key, (const char *) key, new_len);
			if (object->type == TYPE_STRING_TO_INT) {
				ZVAL_LONG(&it->data, JUDY_LVAL_READ(PValue));
			} else {
				zval *value = JUDY_MVAL_READ(PValue);

				ZVAL_COPY(&it->data, value);
			}
			it->valid = 1;
		}
	} else if (object->type == TYPE_STRING_TO_MIXED_HASH
			|| object->type == TYPE_STRING_TO_INT_HASH
			|| object->type == TYPE_STRING_TO_MIXED_ADAPTIVE
			|| object->type == TYPE_STRING_TO_INT_ADAPTIVE) {

		uint8_t      *key = it->key_scratch;
		Pvoid_t      *KValue;

		/* Rewind via key_index (JudySL) for alphabetical ordering */
		key[0] = '\0';
		JSLF(KValue, object->key_index, key);

		if (KValue != NULL && KValue != PJERR) {
			size_t new_len = strlen((char *)key);
			zval_ptr_dtor(&it->key);
			ZVAL_STRINGL(&it->key, (const char *) key, new_len);

			Pvoid_t *VValue = NULL;
			if (object->type == TYPE_STRING_TO_MIXED_ADAPTIVE || object->type == TYPE_STRING_TO_INT_ADAPTIVE) {
				Word_t sso_idx;
				if (judy_pack_short_string_internal((char *)key, new_len, &sso_idx)) {
					JLG(VValue, object->array, sso_idx);
				} else {
					JHSG(VValue, object->hs_array, key, (Word_t)new_len);
				}
			} else {
				JHSG(VValue, object->array, key, (Word_t)new_len);
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				if (object->type == TYPE_STRING_TO_INT_HASH || object->type == TYPE_STRING_TO_INT_ADAPTIVE) {
					ZVAL_LONG(&it->data, JUDY_LVAL_READ(VValue));
				} else {
					zval *value = JUDY_MVAL_READ(VValue);
					ZVAL_COPY(&it->data, value);
				}
			} else {
				ZVAL_NULL(&it->data);
			}
			it->valid = 1;
		}
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

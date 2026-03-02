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

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_judy.h"
#include "judy_handlers.h"
#include "judy_arrayaccess.h"
#include "judy_iterator.h"
#include "ext/spl/spl_iterators.h"
#include "ext/json/php_json.h"
#include "ext/standard/php_var.h"
#include "zend_smart_str.h"
#include "Judy_arginfo.h"

ZEND_DECLARE_MODULE_GLOBALS(judy)

/* declare judy class entry */
zend_class_entry *judy_ce;
zend_object_handlers judy_handlers;

	/* {{{ php_judy_init_globals
	*/
static void php_judy_init_globals(zend_judy_globals *judy_globals)
{
	judy_globals->max_length = 65536;
}
/* }}} */

/* {{{ judy_free_storage
   close all resources and the memory allocated for the object */
static Word_t judy_free_array_internal(judy_object *intern)
{
	Word_t Rc_word = 0;

	if (intern->array == NULL) {
		return 0;
	}

	if (intern->type == TYPE_INT_TO_MIXED) {
		Word_t index = 0;
		Word_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			zval *value = JUDY_MVAL_READ(PValue);
			zval_ptr_dtor(value);
			efree(value);
			JLN(PValue, intern->array, index);
		}
		JLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_INT_TO_PACKED) {
		Word_t index = 0;
		Word_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (packed) {
				efree(packed);
			}
			JLN(PValue, intern->array, index);
		}
		JLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		uint8_t *kindex = intern->key_scratch;
		Word_t *PValue;

		kindex[0] = '\0';
		JSLF(PValue, intern->array, kindex);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			zval *value = JUDY_MVAL_READ(PValue);
			zval_ptr_dtor(value);
			efree(value);
			JSLN(PValue, intern->array, kindex);
		}
		JSLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH) {
		uint8_t *kindex = intern->key_scratch;
		Word_t *PValue;
		Pvoid_t *HValue;

		/* Enumerate keys via key_index (JudySL), free zvals via JudyHS lookup */
		kindex[0] = '\0';
		JSLF(PValue, intern->key_index, kindex);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JHSG(HValue, intern->array, kindex, (Word_t)strlen((char *)kindex));
			if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
				zval *value = JUDY_MVAL_READ(HValue);
				zval_ptr_dtor(value);
				efree(value);
			}
			JSLN(PValue, intern->key_index, kindex);
		}
		JHSFA(Rc_word, intern->array);
		JSLFA(Rc_word, intern->key_index);
		intern->key_index = NULL;
	} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
		/* JudyHS stores Word_t values (no zval allocation), just free both arrays */
		JHSFA(Rc_word, intern->array);
		JSLFA(Rc_word, intern->key_index);
		intern->key_index = NULL;
	} else if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
		uint8_t *kindex = intern->key_scratch;
		Word_t *PValue;
		Pvoid_t *HValue;

		kindex[0] = '\0';
		JSLF(PValue, intern->key_index, kindex);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			if (klen < 8) {
				Word_t index = 0;
				memcpy(&index, kindex, klen);
				JLG(HValue, intern->array, index);
			} else {
				JHSG(HValue, intern->hs_array, kindex, klen);
			}
			if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
				zval *value = JUDY_MVAL_READ(HValue);
				zval_ptr_dtor(value);
				efree(value);
			}
			JSLN(PValue, intern->key_index, kindex);
		}
		JLFA(Rc_word, intern->array);
		JHSFA(Rc_word, intern->hs_array);
		JSLFA(Rc_word, intern->key_index);
		intern->key_index = NULL;
		intern->hs_array = NULL;
	} else if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
		JLFA(Rc_word, intern->array);
		JHSFA(Rc_word, intern->hs_array);
		JSLFA(Rc_word, intern->key_index);
		intern->key_index = NULL;
		intern->hs_array = NULL;
	} else if (intern->type == TYPE_BITSET) {
		J1FA(Rc_word, intern->array);
	} else if (intern->type == TYPE_INT_TO_INT) {
		JLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_STRING_TO_INT) {
		JSLFA(Rc_word, intern->array);
	}

	intern->array = NULL;
	intern->counter = 0;

	return Rc_word;
}

/* {{{ judy_pack_value — pack a zval into a heap-allocated judy_packed_value.
   Scalars are stored directly (fast path). Arrays/objects fall back to serialize.
   Returns NULL on failure. Caller owns the returned memory. */
judy_packed_value *judy_pack_value(zval *value)
{
	judy_packed_value *packed;

	switch (Z_TYPE_P(value)) {
	case IS_LONG:
		packed = emalloc(offsetof(judy_packed_value, v) + sizeof(zend_long));
		packed->tag = JUDY_TAG_LONG;
		packed->v.lval = Z_LVAL_P(value);
		return packed;
	case IS_DOUBLE:
		packed = emalloc(offsetof(judy_packed_value, v) + sizeof(double));
		packed->tag = JUDY_TAG_DOUBLE;
		packed->v.dval = Z_DVAL_P(value);
		return packed;
	case IS_TRUE:
		packed = emalloc(offsetof(judy_packed_value, v));
		packed->tag = JUDY_TAG_TRUE;
		return packed;
	case IS_FALSE:
		packed = emalloc(offsetof(judy_packed_value, v));
		packed->tag = JUDY_TAG_FALSE;
		return packed;
	case IS_NULL:
		packed = emalloc(offsetof(judy_packed_value, v));
		packed->tag = JUDY_TAG_NULL;
		return packed;
	case IS_STRING:
		{
			size_t len = Z_STRLEN_P(value);
			packed = emalloc(offsetof(judy_packed_value, v) + sizeof(size_t) + len);
			packed->tag = JUDY_TAG_STRING;
			packed->v.str.len = len;
			memcpy(packed->v.str.data, Z_STRVAL_P(value), len);
			return packed;
		}
	default:
		/* Arrays, objects, etc. — fall back to php_var_serialize */
		{
			smart_str buf = {0};
			php_serialize_data_t var_hash;

			PHP_VAR_SERIALIZE_INIT(var_hash);
			php_var_serialize(&buf, value, &var_hash);
			PHP_VAR_SERIALIZE_DESTROY(var_hash);

			if (EG(exception) || !buf.s) {
				smart_str_free(&buf);
				return NULL;
			}

			size_t len = ZSTR_LEN(buf.s);
			packed = emalloc(offsetof(judy_packed_value, v) + sizeof(size_t) + len);
			packed->tag = JUDY_TAG_SERIALIZED;
			packed->v.str.len = len;
			memcpy(packed->v.str.data, ZSTR_VAL(buf.s), len);

			smart_str_free(&buf);
			return packed;
		}
	}
}
/* }}} */

/* {{{ judy_unpack_value — reconstruct a zval from a judy_packed_value into rv.
   Scalars are reconstructed directly (fast path). Tag 255 uses unserialize.
   Returns SUCCESS/FAILURE. On success, rv holds the reconstructed value. */
int judy_unpack_value(judy_packed_value *packed, zval *rv)
{
	switch ((judy_packed_tag)packed->tag) {
	case JUDY_TAG_LONG:
		ZVAL_LONG(rv, packed->v.lval);
		return SUCCESS;
	case JUDY_TAG_DOUBLE:
		ZVAL_DOUBLE(rv, packed->v.dval);
		return SUCCESS;
	case JUDY_TAG_TRUE:
		ZVAL_TRUE(rv);
		return SUCCESS;
	case JUDY_TAG_FALSE:
		ZVAL_FALSE(rv);
		return SUCCESS;
	case JUDY_TAG_NULL:
		ZVAL_NULL(rv);
		return SUCCESS;
	case JUDY_TAG_STRING:
		ZVAL_STRINGL(rv, packed->v.str.data, packed->v.str.len);
		return SUCCESS;
	case JUDY_TAG_SERIALIZED:
		{
			php_unserialize_data_t var_hash;
			const unsigned char *p = (const unsigned char *)packed->v.str.data;
			const unsigned char *end = p + packed->v.str.len;

			PHP_VAR_UNSERIALIZE_INIT(var_hash);
			int result = php_var_unserialize(rv, &p, end, &var_hash);
			PHP_VAR_UNSERIALIZE_DESTROY(var_hash);

			if (!result) {
				zval_ptr_dtor(rv);
				ZVAL_NULL(rv);
				return FAILURE;
			}
			return SUCCESS;
		}
	default:
		ZVAL_NULL(rv);
		return FAILURE;
	}
}
/* }}} */

static void judy_object_free_storage(zend_object *object)
{
	judy_object *intern = php_judy_object(object);

	/* Clean up iterator state */
	zval_ptr_dtor(&intern->iterator_key);
	zval_ptr_dtor(&intern->iterator_data);

	/* Free the Judy array if __destruct didn't already */
	judy_free_array_internal(intern);

	if (intern->key_scratch) {
		efree(intern->key_scratch);
	}

	zend_object_std_dtor(&intern->std);
}
/* }}} */

static inline void judy_init_type_flags(judy_object *intern, zend_long jtype)
{
	intern->type = jtype;
	intern->is_integer_keyed = (jtype == TYPE_BITSET || jtype == TYPE_INT_TO_INT || jtype == TYPE_INT_TO_MIXED || jtype == TYPE_INT_TO_PACKED);
	intern->is_string_keyed = (jtype == TYPE_STRING_TO_INT || jtype == TYPE_STRING_TO_MIXED || jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_INT_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
	intern->is_mixed_value = (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED || jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE);
	intern->is_packed_value = (jtype == TYPE_INT_TO_PACKED);
	intern->is_hash_keyed = (jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_INT_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
	intern->is_adaptive = (jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
}

/* {{{ judy_object_new_ex
*/
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr)
{
	judy_object *intern;

	intern = ecalloc(1, sizeof(judy_object) + zend_object_properties_size(ce));
	if (ptr) {
		*ptr = intern;
	}

	intern->next_empty_is_valid = 1;
	intern->next_empty = 0;
	intern->hs_array = (Pvoid_t) NULL;

	/* Initialize iterator state */
	ZVAL_UNDEF(&intern->iterator_key);
	ZVAL_UNDEF(&intern->iterator_data);
	intern->iterator_initialized = 0;

	zend_object_std_init(&(intern->std), ce);
	object_properties_init(&intern->std, ce);

	intern->std.handlers = &judy_handlers;
	intern->key_scratch = emalloc(PHP_JUDY_MAX_LENGTH);
	return &intern->std;
}
/* }}} */

/* {{{ judy_object_new
*/
zend_object *judy_object_new(zend_class_entry *ce)
{
	return judy_object_new_ex(ce, NULL);
}
/* }}} */

PHP_JUDY_API zend_class_entry *php_judy_ce(void)
{
	return judy_ce;
}

/* {{{ PHP INI entries
*/
PHP_INI_BEGIN()
	STD_PHP_INI_ENTRY("judy.string.maxlength", "65536", PHP_INI_ALL, OnUpdateLong, max_length, zend_judy_globals, judy_globals)
PHP_INI_END()
/* }}} */

#define CHECK_ARRAY_AND_ARG_TYPE(_index_, _string_key_, _error_flag_, _return_)	\
	switch (intern->type) {					\
		case TYPE_BITSET:					\
		case TYPE_INT_TO_INT:				\
		case TYPE_INT_TO_MIXED:				\
		case TYPE_INT_TO_PACKED:			\
			_index_ = zval_get_long(offset);\
			break;							\
		case TYPE_STRING_TO_INT:			\
		case TYPE_STRING_TO_MIXED:			\
		case TYPE_STRING_TO_MIXED_HASH:		\
		case TYPE_STRING_TO_INT_HASH:		\
		case TYPE_STRING_TO_MIXED_ADAPTIVE:	\
		case TYPE_STRING_TO_INT_ADAPTIVE:	\
			if (UNEXPECTED(Z_TYPE_P(offset) != IS_STRING)) {	\
				zend_throw_error(zend_ce_type_error, "Judy offset must be of type string for string-based arrays"); \
				_error_flag_ = 1; \
			} else {								\
				_string_key_ = offset;	\
			} \
			break;							\
		default:							\
			php_error_docref(NULL, E_WARNING, "invalid Judy Array type, please report");	\
			_return_;						\
	}

static inline int judy_pack_short_string(const char *str, size_t len, Word_t *index)
{
	if (len >= 8) return 0;
	*index = 0;
	memcpy(index, str, len);
	return 1;
}

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv) /* {{{ */
{
	zend_long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL && intern->hs_array == NULL) {
		return NULL;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return NULL);
	if (error_flag) {
		return NULL;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		ZVAL_BOOL(rv, Rc_int);
		return rv;
	}

	if (intern->is_adaptive && pstring_key) {
		Word_t sso_idx;
		if (judy_pack_short_string(Z_STRVAL_P(pstring_key), Z_STRLEN_P(pstring_key), &sso_idx)) {
			JLG(PValue, intern->array, sso_idx);
		} else {
			JHSG(PValue, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), (Word_t)Z_STRLEN_P(pstring_key));
		}
	} else {
		switch(intern->type) {
			case TYPE_INT_TO_INT:
			case TYPE_INT_TO_MIXED:
			case TYPE_INT_TO_PACKED:
				JLG(PValue, intern->array, j_index);
				break;
			case TYPE_STRING_TO_INT:
			case TYPE_STRING_TO_MIXED:
				JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
				break;
			case TYPE_STRING_TO_MIXED_HASH:
			case TYPE_STRING_TO_INT_HASH:
				JHSG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), (Word_t)Z_STRLEN_P(pstring_key));
				break;
		}
	}

	if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
		if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT
				|| intern->type == TYPE_STRING_TO_INT_HASH || intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
			ZVAL_LONG(rv, JUDY_LVAL_READ(PValue));
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED
				|| intern->type == TYPE_STRING_TO_MIXED_HASH || intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
			ZVAL_COPY(rv, JUDY_MVAL_READ(PValue));
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (JUDY_LIKELY(packed != NULL)) {
				judy_unpack_value(packed, rv);
			} else {
				ZVAL_NULL(rv);
			}
		}
		return rv;
	}
	return NULL;
}
/* }}} */

static zval *judy_object_read_dimension(zend_object *obj, zval *offset, int type, zval *rv)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    return judy_object_read_dimension_helper(&object_zv, offset, rv);
}

int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value) /* {{{ */
{
	zend_long index;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (offset) {
		CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return FAILURE);
		if (error_flag) {
			return FAILURE;
		}
		if (pstring_key && Z_STRLEN_P(pstring_key) >= PHP_JUDY_MAX_LENGTH) {
			zend_throw_exception_ex(NULL, 0,
				"Judy string key length (%zu) exceeds maximum of %d bytes",
				(size_t)Z_STRLEN_P(pstring_key), PHP_JUDY_MAX_LENGTH - 1);
			return FAILURE;
		}
	} else {
		if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED
				|| intern->type == TYPE_STRING_TO_MIXED_HASH || intern->type == TYPE_STRING_TO_INT_HASH) {
			zend_throw_exception(NULL, "Judy STRING_TO_INT, STRING_TO_MIXED, STRING_TO_MIXED_HASH and STRING_TO_INT_HASH values cannot be set without specifying a key", 0);
			return FAILURE;
		}
	}

	if (intern->type == TYPE_BITSET) {
		int         Rc_int;

		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					J1L(Rc_int, intern->array, index);

					if (Rc_int == 1) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		if (zend_is_true(value)) {
			J1S(Rc_int, intern->array, index);
			if (Rc_int == 1) intern->counter++;
		} else {
			J1U(Rc_int, intern->array, index);
			if (Rc_int == 1) intern->counter--;
		}
		return Rc_int ? SUCCESS : FAILURE;
	} else if (intern->type == TYPE_INT_TO_INT
			|| intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		Pvoid_t     *PValue;

		/* Common: determine the target index (identical for all JudyL-backed types). */
		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					JLL(PValue, intern->array, index);

					if (PValue != NULL && PValue != PJERR) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		/* Type-specific: value preparation and JLI insertion. */
		if (intern->type == TYPE_INT_TO_INT) {
			PWord_t PExisting;
			zend_long lval = zval_get_long(value);
			JLG(PExisting, intern->array, index);
			JLI(PValue, intern->array, index);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				JUDY_LVAL_WRITE(PValue, lval);
				if (PExisting == NULL) intern->counter++;
				return SUCCESS;
			}
			return FAILURE;
		} else if (intern->type == TYPE_INT_TO_MIXED) {
			JLI(PValue, intern->array, index);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				zval *old_value, *new_value;
				if (JUDY_MVAL_READ(PValue) != NULL) {
					old_value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(old_value);
					efree(old_value);
				} else {
					intern->counter++;
				}
				new_value = emalloc(sizeof(zval));
				ZVAL_COPY(new_value, value);
				JUDY_MVAL_WRITE(PValue, new_value);
				return SUCCESS;
			}
			return FAILURE;
		} else { /* TYPE_INT_TO_PACKED */
			judy_packed_value *packed = judy_pack_value(value);
			if (JUDY_UNLIKELY(!packed)) {
				return FAILURE;
			}
			JLI(PValue, intern->array, index);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				judy_packed_value *old = JUDY_PVAL_READ(PValue);
				if (old != NULL) {
					efree(old);
				} else {
					intern->counter++;
				}
				JUDY_PVAL_WRITE(PValue, packed);
				return SUCCESS;
			}
			efree(packed);
			return FAILURE;
		}
	} else if (intern->type == TYPE_STRING_TO_INT) {
		PWord_t     *PValue;
		PWord_t     *PExisting;
		zend_long lval = zval_get_long(value);
		int res;

		/* Check if key already exists before insert to track count correctly */
		JSLG(PExisting, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			if (PExisting == NULL) {
				intern->counter++;
			}
			JUDY_LVAL_WRITE(PValue, lval);
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		Pvoid_t *PValue;
		int res;

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			zval *old_value, *new_value;
			if (JUDY_MVAL_READ(PValue) != NULL) {
				old_value = JUDY_MVAL_READ(PValue);
				zval_ptr_dtor(old_value);
				efree(old_value);
			} else {
				intern->counter++;
			}
			new_value = emalloc(sizeof(zval));
			ZVAL_COPY(new_value, value);
			JUDY_MVAL_WRITE(PValue, new_value);
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH) {
		Pvoid_t *HValue;
		Pvoid_t *KValue;
		int res;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);

		/* JudySL key_index uses null-terminated strings; reject embedded NULs */
		if (memchr(Z_STRVAL_P(pstring_key), '\0', Z_STRLEN_P(pstring_key)) != NULL) {
			zend_throw_exception(NULL,
				"Judy STRING_TO_MIXED_HASH keys must not contain embedded null bytes", 0);
			return FAILURE;
		}

		JHSI(HValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
		if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
			zval *old_value, *new_value;
			if (JUDY_MVAL_READ(HValue) != NULL) {
				old_value = JUDY_MVAL_READ(HValue);
				zval_ptr_dtor(old_value);
				efree(old_value);
			} else {
				/* Register key in JudySL key_index for iteration support */
				JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
				if (JUDY_UNLIKELY(KValue == PJERR)) {
					/* Rollback the JudyHS insertion */
					int Rc_tmp = 0;
					JHSD(Rc_tmp, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
					return FAILURE;
				}
				intern->counter++;
			}
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, value);
			JUDY_MVAL_WRITE(HValue, new_value);
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
		Pvoid_t *HExisting;
		Pvoid_t *HValue;
		Pvoid_t *KValue;
		int res;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);

		/* JudySL key_index uses null-terminated strings; reject embedded NULs */
		if (JUDY_UNLIKELY(memchr(Z_STRVAL_P(pstring_key), '\0', Z_STRLEN_P(pstring_key)) != NULL)) {
			zend_throw_exception(NULL,
				"Judy STRING_TO_INT_HASH keys must not contain embedded null bytes", 0);
			return FAILURE;
		}

		/* Check if key already exists before insert to track count correctly */
		JHSG(HExisting, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);

		JHSI(HValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
		if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
			if (HExisting == NULL) {
				/* New key — register in key_index for iteration */
				JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
				if (JUDY_UNLIKELY(KValue == PJERR)) {
					int Rc_tmp = 0;
					JHSD(Rc_tmp, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
					return FAILURE;
				}
				intern->counter++;
			}
			JUDY_LVAL_WRITE(HValue, zval_get_long(value));
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
		Pvoid_t *PValue;
		Pvoid_t *KValue;
		int res;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);
		Word_t sso_idx;

		if (judy_pack_short_string(Z_STRVAL_P(pstring_key), key_len, &sso_idx)) {
			JLI(PValue, intern->array, sso_idx);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				if (*(Word_t *)PValue == 0) {
					/* Register in key_index for iteration */
					JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
					if (JUDY_UNLIKELY(KValue == PJERR)) {
						JLD(res, intern->array, sso_idx);
						return FAILURE;
					}
					intern->counter++;
				}
				JUDY_LVAL_WRITE(PValue, zval_get_long(value));
				res = SUCCESS;
			} else {
				res = FAILURE;
			}
		} else {
			JHSI(PValue, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				if (*(Word_t *)PValue == 0) {
					JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
					if (JUDY_UNLIKELY(KValue == PJERR)) {
						int Rc_tmp;
						JHSD(Rc_tmp, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
						return FAILURE;
					}
					intern->counter++;
				}
				JUDY_LVAL_WRITE(PValue, zval_get_long(value));
				res = SUCCESS;
			} else {
				res = FAILURE;
			}
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
		Pvoid_t *PValue;
		Pvoid_t *KValue;
		int res;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);
		Word_t sso_idx;

		if (judy_pack_short_string(Z_STRVAL_P(pstring_key), key_len, &sso_idx)) {
			JLI(PValue, intern->array, sso_idx);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				if (*(Pvoid_t *)PValue != NULL) {
					zval *old_value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(old_value);
					efree(old_value);
				} else {
					JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
					if (JUDY_UNLIKELY(KValue == PJERR)) {
						JLD(res, intern->array, sso_idx);
						return FAILURE;
					}
					intern->counter++;
				}
				zval *new_value = emalloc(sizeof(zval));
				ZVAL_COPY(new_value, value);
				JUDY_MVAL_WRITE(PValue, new_value);
				res = SUCCESS;
			} else {
				res = FAILURE;
			}
		} else {
			JHSI(PValue, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				if (*(Pvoid_t *)PValue != NULL) {
					zval *old_value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(old_value);
					efree(old_value);
				} else {
					JSLI(KValue, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
					if (JUDY_UNLIKELY(KValue == PJERR)) {
						int Rc_tmp;
						JHSD(Rc_tmp, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
						return FAILURE;
					}
					intern->counter++;
				}
				zval *new_value = emalloc(sizeof(zval));
				ZVAL_COPY(new_value, value);
				JUDY_MVAL_WRITE(PValue, new_value);
				res = SUCCESS;
			} else {
				res = FAILURE;
			}
		}
		return res;
	}
	return FAILURE;
}
/* }}} */

static void judy_object_write_dimension(zend_object *obj, zval *offset, zval *value)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    judy_object_write_dimension_helper(&object_zv, offset, value);
}

int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty) /* {{{ */
{
	int Rc_int = 0;
	zend_long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL) {
		return 0;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return 0);
	if (error_flag) {
		return 0;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		return Rc_int;
	}

	if (pstring_key) {
		if (intern->is_adaptive) {
			Word_t sso_idx;
			if (judy_pack_short_string(Z_STRVAL_P(pstring_key), Z_STRLEN_P(pstring_key), &sso_idx)) {
				JLG(PValue, intern->array, sso_idx);
			} else {
				JHSG(PValue, intern->hs_array, (uint8_t *)Z_STRVAL_P(pstring_key), (Word_t)Z_STRLEN_P(pstring_key));
			}
		} else {
			switch(intern->type) {
				case TYPE_STRING_TO_INT:
				case TYPE_STRING_TO_MIXED:
					JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
					break;
				case TYPE_STRING_TO_MIXED_HASH:
				case TYPE_STRING_TO_INT_HASH:
					JHSG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), (Word_t)Z_STRLEN_P(pstring_key));
					break;
			}
		}
	} else {
		JLG(PValue, intern->array, j_index);
	}

	if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
		if (!check_empty) {
			return 1;
		} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT
				|| intern->type == TYPE_STRING_TO_INT_HASH) {
			if (JUDY_LVAL_READ(PValue)) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED
				|| intern->type == TYPE_STRING_TO_MIXED_HASH) {
			if (JUDY_MVAL_READ(PValue) && zend_is_true(JUDY_MVAL_READ(PValue))) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (JUDY_LIKELY(packed != NULL)) {
				zval tmp;
				ZVAL_UNDEF(&tmp);
				judy_unpack_value(packed, &tmp);
				int result = zend_is_true(&tmp);
				zval_ptr_dtor(&tmp);
				return result;
			}
			return 0;
		}
	}
	return 0;
}
/* }}} */

static int judy_object_has_dimension(zend_object *obj, zval *offset, int check_empty)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    return judy_object_has_dimension_helper(&object_zv, offset, check_empty);
}

int judy_object_unset_dimension_helper(zval *object, zval *offset) /* {{{ */
{
	int Rc_int = 0;
	zend_long index = 0;
	Word_t j_index;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL) {
		return FAILURE;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return FAILURE);
	if (error_flag) {
		return FAILURE;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		J1U(Rc_int, intern->array, j_index);
		if (Rc_int == 1) intern->counter--;
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		if (intern->type == TYPE_INT_TO_INT) {
			JLD(Rc_int, intern->array, j_index);
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (JUDY_LIKELY(packed != NULL)) {
					efree(packed);
				}
				JLD(Rc_int, intern->array, j_index);
			}
		} else {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				zval *value = JUDY_MVAL_READ(PValue);
				zval_ptr_dtor(value);
				efree(value);
				JLD(Rc_int, intern->array, j_index);
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
	} else if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE || intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
		uint8_t *key = (uint8_t *)Z_STRVAL_P(pstring_key);
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);
		Word_t sso_idx;

		if (judy_pack_short_string((char *)key, key_len, &sso_idx)) {
			Pvoid_t *PValue;
			JLG(PValue, intern->array, sso_idx);
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
					zval *value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(value);
					efree(value);
				}
				JLD(Rc_int, intern->array, sso_idx);
				if (Rc_int == 1) {
					int Rc_idx_del;
					JSLD(Rc_idx_del, intern->key_index, key);
					intern->counter--;
				}
			}
		} else {
			Pvoid_t *HValue;
			JHSG(HValue, intern->hs_array, key, key_len);
			if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
				if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
					zval *value = JUDY_MVAL_READ(HValue);
					zval_ptr_dtor(value);
					efree(value);
				}
				JHSD(Rc_int, intern->hs_array, key, key_len);
				if (Rc_int == 1) {
					int Rc_idx_del;
					JSLD(Rc_idx_del, intern->key_index, key);
					intern->counter--;
				}
			}
		}
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		if (intern->type == TYPE_STRING_TO_INT) {
			JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		} else {
			Pvoid_t     *PValue;
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				zval *value = JUDY_MVAL_READ(PValue);
				zval_ptr_dtor(value);
				efree(value);
				JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH) {
		Pvoid_t     *HValue;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);

		JHSG(HValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
		if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
			zval *value = JUDY_MVAL_READ(HValue);
			zval_ptr_dtor(value);
			efree(value);
			JHSD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
			if (Rc_int == 1) {
				int Rc_del = 0;
				JSLD(Rc_del, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
				if (Rc_del == 1) {
					intern->counter--;
				}
			}
		}
	} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
		Pvoid_t     *HValue;
		Word_t key_len = (Word_t)Z_STRLEN_P(pstring_key);

		JHSG(HValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
		if (HValue != NULL && HValue != PJERR) {
			/* No zval to free — value is stored inline as Word_t */
			JHSD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key), key_len);
			if (Rc_int == 1) {
				int Rc_del = 0;
				JSLD(Rc_del, intern->key_index, (uint8_t *)Z_STRVAL_P(pstring_key));
				if (Rc_del == 1) {
					intern->counter--;
				}
			}
		}
	}
	return Rc_int ? SUCCESS : FAILURE;
}
/* }}} */

static void judy_object_unset_dimension(zend_object *obj, zval *offset)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    judy_object_unset_dimension_helper(&object_zv, offset);
}

/* {{{ PHP_MINIT_FUNCTION
*/
PHP_MINIT_FUNCTION(judy)
{
	zend_class_entry ce;

	ZEND_INIT_MODULE_GLOBALS(judy, php_judy_init_globals, NULL);

	REGISTER_INI_ENTRIES();

	/* Judy class definition */

	INIT_CLASS_ENTRY(ce, "Judy", class_Judy_methods);

	judy_ce = zend_register_internal_class_ex(&ce, NULL);
	judy_ce->create_object = judy_object_new;

	memcpy(&judy_handlers, zend_get_std_object_handlers(),
			sizeof(zend_object_handlers));

	/* set some internal handlers */
	judy_handlers.clone_obj = judy_object_clone;
	judy_handlers.read_dimension = judy_object_read_dimension;
	judy_handlers.write_dimension = judy_object_write_dimension;
	judy_handlers.unset_dimension = judy_object_unset_dimension;
	judy_handlers.has_dimension = judy_object_has_dimension;
	judy_handlers.dtor_obj = zend_objects_destroy_object;
	judy_handlers.free_obj = judy_object_free_storage;
	judy_handlers.offset = XtOffsetOf(judy_object, std);

	/* implements some interface to provide access to judy object as an array */
	zend_class_implements(judy_ce, 4, zend_ce_arrayaccess, zend_ce_countable, zend_ce_iterator, php_json_serializable_ce);

	judy_ce->get_iterator = judy_get_iterator;

	REGISTER_STRING_CONSTANT("JUDY_VERSION", PHP_JUDY_VERSION, CONST_PERSISTENT);

	REGISTER_JUDY_CLASS_CONST_LONG("BITSET", TYPE_BITSET);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_INT", TYPE_INT_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_MIXED", TYPE_INT_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT", TYPE_STRING_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED", TYPE_STRING_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_PACKED", TYPE_INT_TO_PACKED);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED_HASH", TYPE_STRING_TO_MIXED_HASH);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT_HASH", TYPE_STRING_TO_INT_HASH);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED_ADAPTIVE", TYPE_STRING_TO_MIXED_ADAPTIVE);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT_ADAPTIVE", TYPE_STRING_TO_INT_ADAPTIVE);

	return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
*/
PHP_RINIT_FUNCTION(judy)
{
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MSHUTDOWN_FUNCTION
*/
PHP_MSHUTDOWN_FUNCTION(judy)
{
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
*/
PHP_MINFO_FUNCTION(judy)
{
	char buf[64];

	php_info_print_table_start();
	php_info_print_table_header(2, "Judy support", "enabled");
	php_info_print_table_row(2, "PHP Judy version", PHP_JUDY_VERSION);
	snprintf(buf, sizeof(buf), "%zu", sizeof(Word_t));
	php_info_print_table_row(2, "sizeof(Word_t)", buf);
	snprintf(buf, sizeof(buf), "%zu", sizeof(Pvoid_t));
	php_info_print_table_row(2, "sizeof(Pvoid_t)", buf);
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ proto Judy::__construct(long type)
   Constructs a new Judy array of the given type */
PHP_METHOD(Judy, __construct)
{
	zend_long               type;
	judy_type               jtype;

	JUDY_METHOD_GET_OBJECT

	JUDY_METHOD_ERROR_HANDLING;

	if (intern->type) {
		zend_throw_exception(NULL, "Judy Array already instantiated", 0);
	} else if (ZEND_NUM_ARGS() > 0) {
		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(type)
		ZEND_PARSE_PARAMETERS_END_EX(
			zend_restore_error_handling(&error_handling);
			return;
		);

		JTYPE(jtype, type);
		if (jtype == 0) {
			zend_restore_error_handling(&error_handling);
			return;
		}
#if !JUDY_MIXED_SUPPORTED
		if (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED
				|| jtype == TYPE_INT_TO_PACKED || jtype == TYPE_STRING_TO_MIXED_HASH) {
			zend_throw_exception(NULL, "MIXED/PACKED Judy types are not supported on this platform (Word_t too small for pointers)", 0);
			zend_restore_error_handling(&error_handling);
			return;
		}
#endif
		intern->counter = 0;
		intern->array = (Pvoid_t) NULL;
		intern->key_index = (Pvoid_t) NULL;
		intern->hs_array = (Pvoid_t) NULL;

		/* Initialize cached type flags for performance optimization */
		judy_init_type_flags(intern, jtype);
	}

	zend_restore_error_handling(&error_handling);
}
/* }}} */

/* {{{ proto Judy::__destruct()
   Free Judy array and any other references */
PHP_METHOD(Judy, __destruct)
{
	zval *object = getThis();
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	/* Clean up iterator state (set UNDEF to prevent double-free in free_obj) */
	zval_ptr_dtor(&intern->iterator_key);
	ZVAL_UNDEF(&intern->iterator_key);
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	/* Free the Judy array directly (avoids virtual method dispatch overhead) */
	judy_free_array_internal(intern);
}
/* }}} */

/* {{{ proto long Judy::free()
   Free the entire Judy Array. Return the number of bytes freed */
PHP_METHOD(Judy, free)
{
	JUDY_METHOD_GET_OBJECT

	RETURN_LONG(judy_free_array_internal(intern));
}
/* }}} */

/* {{{ proto long Judy::memoryUsage()
   Return the memory used by the Judy Array */
PHP_METHOD(Judy, memoryUsage)
{
	Word_t     Rc_word;

	JUDY_METHOD_GET_OBJECT

		switch (intern->type)
		{
			case TYPE_BITSET:
				J1MU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			case TYPE_INT_TO_INT:
			case TYPE_INT_TO_MIXED:
			case TYPE_INT_TO_PACKED:
				JLMU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			default:
				RETURN_NULL();
		}
}
/* }}} */

/* {{{ proto long Judy::size()
   Return the current size of the array. */
PHP_METHOD(Judy, size)
{
	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_INT_TO_PACKED) {
			zend_long   zl_idx1 = 0;
			zend_long   zl_idx2 = -1;
			Word_t   idx1, idx2;
			Word_t   Rc_word;

			ZEND_PARSE_PARAMETERS_START(0, 2)
				Z_PARAM_OPTIONAL
				Z_PARAM_LONG(zl_idx1)
				Z_PARAM_LONG(zl_idx2)
			ZEND_PARSE_PARAMETERS_END();
			idx1 = (Word_t)zl_idx1;
			idx2 = (Word_t)zl_idx2;

			if (intern->type == TYPE_BITSET) {
				J1C(Rc_word, intern->array, idx1, idx2);
			} else {
				JLC(Rc_word, intern->array, idx1, idx2);
			}

			RETURN_LONG(Rc_word);
		} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED
				|| intern->type == TYPE_STRING_TO_MIXED_HASH || intern->type == TYPE_STRING_TO_INT_HASH) {
			RETURN_LONG(intern->counter);
		}
}

/* {{{ proto long Judy::count()
   Return the current size of the array. */
PHP_METHOD(Judy, count)
{
	JUDY_METHOD_GET_OBJECT

		RETURN_LONG(intern->counter);
}

/* }}} */

/* {{{ proto long Judy::byCount(long nth_index)
   Locate the Nth index that is present in the Judy array (Nth = 1 returns the first index present).
   To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(Judy, byCount)
{

	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_INT_TO_PACKED) {
			zend_long       nth_index;
			Word_t            index;

			ZEND_PARSE_PARAMETERS_START(1, 1)
				Z_PARAM_LONG(nth_index)
			ZEND_PARSE_PARAMETERS_END();

			if (intern->type == TYPE_BITSET) {
				int Rc_int;
				J1BC(Rc_int, intern->array, nth_index, index);
				if (Rc_int == 1)
					RETURN_LONG(index);
			} else {
				PWord_t PValue;
				JLBC(PValue, intern->array, nth_index, index);
				if (PValue != NULL && PValue != PJERR)
					RETURN_LONG(index);
			}
		}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::first([mixed index])
   Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(Judy, first)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		zend_long       zl_index = 0;
		Word_t          index;
		int             Rc_int;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1F(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index = 0;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLF(PValue, intern->array, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH
			|| intern->type == TYPE_STRING_TO_INT_HASH) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLF(PValue, intern->key_index, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::searchNext(mixed index)
   Search (exclusive) for the next index present that is greater than the passed Index

   This method was renamed from next() to avoid Iterator interface conflicts.
   The original search functionality has been moved to searchNext() method.
   Original functionality: Search (exclusive) for the next index present
   that is greater than the passed Index.
   */
PHP_METHOD(Judy, searchNext)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		zend_long       zl_index;
		Word_t          index;
		int             Rc_int;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1N(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLN(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLN(PValue, intern->array, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH
			|| intern->type == TYPE_STRING_TO_INT_HASH) {
		char        *str;
		size_t       str_length;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLN(PValue, intern->key_index, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ Iterator interface next() method - Fixes GitHub issue #25
 *
 * This method was separated from the original Judy::next() search function
 * to resolve naming conflicts with the Iterator interface. The original
 * search functionality has been moved to searchNext() method.
 *
 * This zero-argument method is required by the Iterator interface and
 * is called by foreach loops to advance the iterator position.
 */
PHP_METHOD(Judy, next)
{
	JUDY_METHOD_GET_OBJECT
	
	/* Clear current data */
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	/* Only advance the iterator if it is currently valid */
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_key)) {
		/* Iterator is invalid, do nothing */
		return;
	}

	if (intern->type == TYPE_BITSET) {
		Word_t          index = Z_LVAL_P(&intern->iterator_key);
		int             Rc_int;

		J1N(Rc_int, intern->array, index);

		if (Rc_int) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);
			ZVAL_BOOL(&intern->iterator_data, 1);
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_INTEGER_KEYED(intern)) {
		Word_t          index = Z_LVAL_P(&intern->iterator_key);
		Pvoid_t          *PValue = NULL;

		JLN(PValue, intern->array, index);

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);

			if (intern->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
			} else if (intern->type == TYPE_INT_TO_PACKED) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &intern->iterator_data);
				}
			} else {
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_STRING_KEYED(intern)) {
		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		if (Z_TYPE_P(&intern->iterator_key) == IS_STRING) {
			int key_len;
			key_len = Z_STRLEN_P(&intern->iterator_key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&intern->iterator_key);
			memcpy(key, Z_STRVAL_P(&intern->iterator_key), key_len);
			key[key_len] = '\0';
			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		} else {
			/* Invalid key type, mark iterator as invalid */
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
			return;
		}

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_STRING(&intern->iterator_key, (char *)key);

			if (intern->is_hash_keyed) {
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, key, (Word_t)strlen((char *)key));
				if (HValue != NULL && HValue != PJERR) {
					if (intern->type == TYPE_STRING_TO_INT_HASH) {
						ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(HValue));
					} else {
						zval *value = JUDY_MVAL_READ(HValue);
						ZVAL_COPY(&intern->iterator_data, value);
					}
				}
			} else if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
		}
	}
}
/* }}} */

/* {{{ Iterator interface rewind() method - Fixes GitHub issue #25 */
PHP_METHOD(Judy, rewind)
{
	JUDY_METHOD_GET_OBJECT
	
	/* Clear current data */
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	if (intern->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		J1F(Rc_int, intern->array, index);
		if (Rc_int) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);
			ZVAL_BOOL(&intern->iterator_data, 1);
			intern->iterator_initialized = 1;
		} else {
			/* Array is empty, mark iterator as invalid */
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_INTEGER_KEYED(intern)) {
		Word_t          index = 0;
		Pvoid_t          *PValue = NULL;

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);

			if (JUDY_IS_PACKED_VALUE(intern)) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &intern->iterator_data);
				}
			} else if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_STRING_KEYED(intern)) {
		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_STRING(&intern->iterator_key, (const char *) key);
			if (intern->is_hash_keyed) {
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, key, (Word_t)strlen((char *)key));
				if (HValue != NULL && HValue != PJERR) {
					if (intern->type == TYPE_STRING_TO_INT_HASH) {
						ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(HValue));
					} else {
						zval *value = JUDY_MVAL_READ(HValue);
						ZVAL_COPY(&intern->iterator_data, value);
					}
				}
			} else if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}
	}
}
/* }}} */

/* {{{ Iterator interface valid() method - Fixes GitHub issue #25 */
PHP_METHOD(Judy, valid)
{
	JUDY_METHOD_GET_OBJECT
	
	/*
	 * The iterator is valid if it has been initialized by rewind() or next()
	 * and has not reached the end of the array. The iterator_initialized flag
	 * tracks this state. This avoids performing a slow lookup on every
	 * iteration of a foreach loop.
	 */
	RETURN_BOOL(intern->iterator_initialized);
}
/* }}} */

/* {{{ Iterator interface current() method - Fixes GitHub issue #25 */
PHP_METHOD(Judy, current)
{
	JUDY_METHOD_GET_OBJECT
	
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_data)) {
		RETURN_NULL();
	}

	ZVAL_COPY(return_value, &intern->iterator_data);
}
/* }}} */

/* {{{ Iterator interface key() method - Fixes GitHub issue #25 */
PHP_METHOD(Judy, key)
{
	JUDY_METHOD_GET_OBJECT
	
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_key)) {
		RETURN_NULL();
	}

	ZVAL_COPY(return_value, &intern->iterator_key);
}
/* }}} */

/* {{{ proto mixed Judy::last([mixed index])
   Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(Judy, last)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		zend_long    zl_index = -1;
		Word_t       index;
		int          Rc_int;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1L(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index = -1;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLL(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			memset(key, 0xff, PHP_JUDY_MAX_LENGTH);
			key[PHP_JUDY_MAX_LENGTH-1] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLL(PValue, intern->array, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH
			|| intern->type == TYPE_STRING_TO_INT_HASH) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		if (str_length == 0) {
			memset(key, 0xff, PHP_JUDY_MAX_LENGTH);
			key[PHP_JUDY_MAX_LENGTH-1] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLL(PValue, intern->key_index, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::prev(mixed index)
   Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(Judy, prev)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		zend_long    zl_index;
		Word_t       index;
		int          Rc_int;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1P(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLP(PValue, intern->array, index);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLP(PValue, intern->array, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH
			|| intern->type == TYPE_STRING_TO_INT_HASH) {
		char        *str;
		size_t       str_length;

		uint8_t     *key = intern->key_scratch;
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

		if (str_length == 0) {
			key[0] = '\0';
		} else {
			size_t key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLP(PValue, intern->key_index, key);
		if (JUDY_LIKELY(PValue != NULL && PValue != PJERR))
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto long Judy::firstEmpty([long index])
   Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(Judy, firstEmpty)
{
	zend_long      zl_index = 0;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1FE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
			JLFE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::lastEmpty([long index])
   Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(Judy, lastEmpty)
{
	zend_long      zl_index = -1;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1LE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
			JLLE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::nextEmpty(long index)
   Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(Judy, nextEmpty)
{
	zend_long      zl_index;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1NE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
			JLNE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::prevEmpty(long index)
   Search (exclusive) for the previous index absent that is less than the passed Index */
PHP_METHOD(Judy, prevEmpty)
{
	zend_long      zl_index;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1PE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
			JLPE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ Helper to create a new empty BITSET Judy object as return_value */
static void judy_create_bitset_result(zval *return_value);
/* Forward declaration */
static judy_object *judy_create_result(zval *return_value, judy_type type);

static void judy_create_bitset_result(zval *return_value)
{
	judy_object *result;

	object_init_ex(return_value, judy_ce);
	result = php_judy_object(Z_OBJ_P(return_value));
	result->array = (Pvoid_t) NULL;
	result->counter = 0;
	result->key_index = (Pvoid_t) NULL;
	result->hs_array = (Pvoid_t) NULL;
	judy_init_type_flags(result, TYPE_BITSET);
}
/* }}} */

/* {{{ Helper to validate that both operands support set operations and have the same type */
static int judy_validate_set_operands(judy_object *self, judy_object *other)
{
	if (self->type != TYPE_BITSET && self->type != TYPE_INT_TO_INT && 
		self->type != TYPE_STRING_TO_INT && self->type != TYPE_STRING_TO_INT_HASH) {
		zend_throw_exception(NULL, "Set operations are only supported on BITSET and integer-valued arrays", 0);
		return FAILURE;
	}
	if (other->type != self->type) {
		zend_throw_exception(NULL, "Both Judy arrays must be the same type for set operations", 0);
		return FAILURE;
	}
	return SUCCESS;
}
/* }}} */

/* {{{ proto Judy Judy::union(Judy $other)
   Return a new Judy array containing all indices present in either array.
   For INT_TO_INT, values from self take priority (left-wins). */
PHP_METHOD(Judy, union)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_set_operands(intern, other) == FAILURE) {
		return;
	}

	if (intern->type == TYPE_BITSET) {
		int Rc_int, Rc_set;

		judy_create_bitset_result(return_value);
		result = php_judy_object(Z_OBJ_P(return_value));

		/* Add all indices from self */
		index = 0;
		J1F(Rc_int, intern->array, index);
		while (Rc_int) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error_bitset;
			if (Rc_set == 1) result->counter++;
			J1N(Rc_int, intern->array, index);
		}

		/* Add all indices from other */
		index = 0;
		J1F(Rc_int, other->array, index);
		while (Rc_int) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error_bitset;
			if (Rc_set == 1) result->counter++;
			J1N(Rc_int, other->array, index);
		}

		return;

alloc_error_bitset:
		J1FA(Rc_int, result->array);
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during union", 0);

	} else if (intern->type == TYPE_INT_TO_INT) {
		Pvoid_t *PValue, *PNew;

		result = judy_create_result(return_value, TYPE_INT_TO_INT);

		/* Add all entries from other first (result is empty, every key is new) */
		index = 0;
		JLF(PValue, other->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error_il;
			JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
			result->counter++;
			JLN(PValue, other->array, index);
		}

		/* Add/overwrite with all entries from self (left-wins) */
		index = 0;
		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			Pvoid_t *PExisting;
			JLG(PExisting, result->array, index);
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error_il;
			JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
			if (PExisting == NULL) result->counter++;
			JLN(PValue, intern->array, index);
		}

		return;

alloc_error_il:
		{
			Word_t Rc_word;
			JLFA(Rc_word, result->array);
		}
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during union", 0);
	} else { /* STRING_TO_INT types */
		result = judy_create_result(return_value, intern->type);
		
		/* We can use mergeWith implementation logic here but into 'result' */
		zval res_zv;
		ZVAL_OBJ(&res_zv, &result->std);
		
		/* Add other first, then self (left-wins) */
		zval other_zv;
		ZVAL_OBJ(&other_zv, &other->std);
		judy_object_merge_with_helper(result, other);
		
		zval self_zv;
		ZVAL_OBJ(&self_zv, &intern->std);
		judy_object_merge_with_helper(result, intern);
	}
}
/* }}} */

/* {{{ proto Judy Judy::intersect(Judy $other)
   Return a new Judy array containing only indices present in both arrays.
   For INT_TO_INT, values from self are used. */
PHP_METHOD(Judy, intersect)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_set_operands(intern, other) == FAILURE) {
		return;
	}

	if (intern->type == TYPE_BITSET) {
		Pvoid_t iter_array, test_array;
		int Rc_int, Rc_set;

		judy_create_bitset_result(return_value);
		result = php_judy_object(Z_OBJ_P(return_value));

		/* Iterate the smaller set, test against the larger for better performance */
		{
			Word_t count_self = 0, count_other = 0;
			J1C(count_self, intern->array, 0, -1);
			J1C(count_other, other->array, 0, -1);
			if (count_self <= count_other) {
				iter_array = intern->array;
				test_array = other->array;
			} else {
				iter_array = other->array;
				test_array = intern->array;
			}
		}

		index = 0;
		J1F(Rc_int, iter_array, index);
		while (Rc_int) {
			int in_test;
			J1T(in_test, test_array, index);
			if (in_test) {
				J1S(Rc_set, result->array, index);
				if (Rc_set == JERR) goto alloc_error_bitset;
				if (Rc_set == 1) result->counter++;
			}
			J1N(Rc_int, iter_array, index);
		}

		return;

alloc_error_bitset:
		J1FA(Rc_int, result->array);
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during intersect", 0);

	} else { /* TYPE_INT_TO_INT */
		Pvoid_t iter_array, test_array;
		Pvoid_t *PValue, *PTest, *PNew;
		int self_is_iter;

		result = judy_create_result(return_value, TYPE_INT_TO_INT);

		/* Iterate the smaller set for better performance */
		{
			Word_t count_self = 0, count_other = 0;
			JLC(count_self, intern->array, 0, -1);
			JLC(count_other, other->array, 0, -1);
			if (count_self <= count_other) {
				iter_array = intern->array;
				test_array = other->array;
				self_is_iter = 1;
			} else {
				iter_array = other->array;
				test_array = intern->array;
				self_is_iter = 0;
			}
		}

		index = 0;
		JLF(PValue, iter_array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JLG(PTest, test_array, index);
			if (JUDY_LIKELY(PTest != NULL && PTest != PJERR)) {
				JLI(PNew, result->array, index);
				if (PNew == PJERR) goto alloc_error_il;
				/* Always use self's value (left-wins) */
				if (self_is_iter) {
					JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				} else {
					JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PTest));
				}
				result->counter++;
			}
			JLN(PValue, iter_array, index);
		}

		return;

alloc_error_il:
		{
			Word_t Rc_word;
			JLFA(Rc_word, result->array);
		}
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during intersect", 0);
	} else { /* STRING_TO_INT types */
		result = judy_create_result(return_value, intern->type);
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			int exists = 0;
			Pvoid_t *VOther = NULL;
			if (intern->is_hash_keyed) {
				JHSG(VOther, other->array, key, (Word_t)strlen((char *)key));
				if (VOther != NULL) exists = 1;
			} else {
				JSLG(VOther, other->array, key);
				if (VOther != NULL) exists = 1;
			}

			if (exists) {
				zval zkey, zval_val;
				ZVAL_STRING(&zkey, (const char *)key);
				ZVAL_UNDEF(&zval_val);
				judy_object_read_dimension_helper_zv(intern, &zkey, &zval_val);
				judy_object_write_dimension_helper_zv(result, &zkey, &zval_val);
				zval_ptr_dtor(&zval_val);
				zval_ptr_dtor(&zkey);
			}

			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		}
	}
}
/* }}} */

/* {{{ proto Judy Judy::diff(Judy $other)
   Return a new Judy array containing indices present in this array but not in $other */
PHP_METHOD(Judy, diff)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_set_operands(intern, other) == FAILURE) {
		return;
	}

	if (intern->type == TYPE_BITSET) {
		int Rc_int, Rc_set;

		judy_create_bitset_result(return_value);
		result = php_judy_object(Z_OBJ_P(return_value));

		/* Iterate self, add to result only if absent in other */
		index = 0;
		J1F(Rc_int, intern->array, index);
		while (Rc_int) {
			int in_other;
			J1T(in_other, other->array, index);
			if (!in_other) {
				J1S(Rc_set, result->array, index);
				if (Rc_set == JERR) goto alloc_error_bitset;
				if (Rc_set == 1) result->counter++;
			}
			J1N(Rc_int, intern->array, index);
		}

		return;

alloc_error_bitset:
		J1FA(Rc_int, result->array);
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during diff", 0);

	} else if (intern->type == TYPE_INT_TO_INT) {
		Pvoid_t *PValue, *PTest, *PNew;

		result = judy_create_result(return_value, TYPE_INT_TO_INT);

		/* Iterate self, add to result only if absent in other */
		index = 0;
		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JLG(PTest, other->array, index);
			if (JUDY_UNLIKELY(PTest == NULL || PTest == PJERR)) {
				JLI(PNew, result->array, index);
				if (PNew == PJERR) goto alloc_error_il;
				JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				result->counter++;
			}
			JLN(PValue, intern->array, index);
		}

		return;

alloc_error_il:
		{
			Word_t Rc_word;
			JLFA(Rc_word, result->array);
		}
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during diff", 0);
	} else { /* STRING_TO_INT types */
		result = judy_create_result(return_value, intern->type);
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			int exists = 0;
			Pvoid_t *VOther = NULL;
			if (intern->is_hash_keyed) {
				JHSG(VOther, other->array, key, (Word_t)strlen((char *)key));
				if (VOther != NULL) exists = 1;
			} else {
				JSLG(VOther, other->array, key);
				if (VOther != NULL) exists = 1;
			}

			if (!exists) {
				zval zkey, zval_val;
				ZVAL_STRING(&zkey, (const char *)key);
				ZVAL_UNDEF(&zval_val);
				judy_object_read_dimension_helper_zv(intern, &zkey, &zval_val);
				judy_object_write_dimension_helper_zv(result, &zkey, &zval_val);
				zval_ptr_dtor(&zval_val);
				zval_ptr_dtor(&zkey);
			}

			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		}
	}
}
/* }}} */

/* {{{ proto Judy Judy::xor(Judy $other)
   Return a new Judy array containing indices present in exactly one of the arrays */
PHP_METHOD(Judy, xor)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_set_operands(intern, other) == FAILURE) {
		return;
	}

	if (intern->type == TYPE_BITSET) {
		int Rc_int, Rc_set;

		judy_create_bitset_result(return_value);
		result = php_judy_object(Z_OBJ_P(return_value));

		/* Add indices from self that are not in other */
		index = 0;
		J1F(Rc_int, intern->array, index);
		while (Rc_int) {
			int in_other;
			J1T(in_other, other->array, index);
			if (!in_other) {
				J1S(Rc_set, result->array, index);
				if (Rc_set == JERR) goto alloc_error_bitset;
				if (Rc_set == 1) result->counter++;
			}
			J1N(Rc_int, intern->array, index);
		}

		/* Add indices from other that are not in self */
		index = 0;
		J1F(Rc_int, other->array, index);
		while (Rc_int) {
			int in_self;
			J1T(in_self, intern->array, index);
			if (!in_self) {
				J1S(Rc_set, result->array, index);
				if (Rc_set == JERR) goto alloc_error_bitset;
				if (Rc_set == 1) result->counter++;
			}
			J1N(Rc_int, other->array, index);
		}

		return;

alloc_error_bitset:
		J1FA(Rc_int, result->array);
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during xor", 0);

	} else if (intern->type == TYPE_INT_TO_INT) {
		Pvoid_t *PValue, *PTest, *PNew;

		result = judy_create_result(return_value, TYPE_INT_TO_INT);

		/* Add entries from self that are not in other */
		index = 0;
		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JLG(PTest, other->array, index);
			if (JUDY_UNLIKELY(PTest == NULL || PTest == PJERR)) {
				JLI(PNew, result->array, index);
				if (PNew == PJERR) goto alloc_error_il;
				JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				result->counter++;
			}
			JLN(PValue, intern->array, index);
		}

		/* Add entries from other that are not in self */
		index = 0;
		JLF(PValue, other->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			JLG(PTest, intern->array, index);
			if (JUDY_UNLIKELY(PTest == NULL || PTest == PJERR)) {
				JLI(PNew, result->array, index);
				if (PNew == PJERR) goto alloc_error_il;
				JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				result->counter++;
			}
			JLN(PValue, other->array, index);
		}

		return;

alloc_error_il:
		{
			Word_t Rc_word;
			JLFA(Rc_word, result->array);
		}
		result->array = NULL;
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		zend_throw_exception(NULL, "Judy: memory allocation failed during xor", 0);
	} else { /* STRING_TO_INT types */
		result = judy_create_result(return_value, intern->type);
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		
		/* 1. Add entries from self that are not in other */
		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			int exists = 0;
			if (intern->is_hash_keyed) {
				Pvoid_t *HExists;
				JHSG(HExists, other->array, key, (Word_t)strlen((char *)key));
				if (HExists != NULL) exists = 1;
			} else {
				Pvoid_t *SExists;
				JSLG(SExists, other->array, key);
				if (SExists != NULL) exists = 1;
			}

			if (!exists) {
				zval zkey, zval_val;
				ZVAL_STRING(&zkey, (const char *)key);
				ZVAL_UNDEF(&zval_val);
				judy_object_read_dimension_helper_zv(intern, &zkey, &zval_val);
				judy_object_write_dimension_helper_zv(result, &zkey, &zval_val);
				zval_ptr_dtor(&zval_val);
				zval_ptr_dtor(&zkey);
			}

			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		}

		/* 2. Add entries from other that are not in self */
		uint8_t *okey = other->key_scratch;
		okey[0] = '\0';
		if (other->is_hash_keyed) {
			JSLF(PValue, other->key_index, okey);
		} else {
			JSLF(PValue, other->array, okey);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			int exists = 0;
			if (intern->is_hash_keyed) {
				Pvoid_t *HExists;
				JHSG(HExists, intern->array, okey, (Word_t)strlen((char *)okey));
				if (HExists != NULL) exists = 1;
			} else {
				Pvoid_t *SExists;
				JSLG(SExists, intern->array, okey);
				if (SExists != NULL) exists = 1;
			}

			if (!exists) {
				zval zkey, zval_val;
				ZVAL_STRING(&zkey, (const char *)okey);
				ZVAL_UNDEF(&zval_val);
				judy_object_read_dimension_helper_zv(other, &zkey, &zval_val);
				judy_object_write_dimension_helper_zv(result, &zkey, &zval_val);
				zval_ptr_dtor(&zval_val);
				zval_ptr_dtor(&zkey);
			}

			if (other->is_hash_keyed) {
				JSLN(PValue, other->key_index, okey);
			} else {
				JSLN(PValue, other->array, okey);
			}
		}
	}
}
/* }}} */

/* {{{ Helper to create a new empty Judy object of the given type as return_value */
static judy_object *judy_create_result(zval *return_value, judy_type type)
{
	judy_object *result;

	object_init_ex(return_value, judy_ce);
	result = php_judy_object(Z_OBJ_P(return_value));
	result->array = (Pvoid_t) NULL;
	result->counter = 0;
	result->key_index = (Pvoid_t) NULL;
	result->hs_array = (Pvoid_t) NULL;
	judy_init_type_flags(result, type);
	return result;
}
/* }}} */

/* {{{ proto Judy Judy::slice(mixed $start, mixed $end)
   Return a new Judy array of the same type containing entries in [$start, $end] inclusive */
PHP_METHOD(Judy, slice)
{
	zval *zstart, *zend_val;
	judy_object *result;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ZVAL(zstart)
		Z_PARAM_ZVAL(zend_val)
	ZEND_PARSE_PARAMETERS_END();

	result = judy_create_result(return_value, intern->type);

	if (intern->type == TYPE_BITSET) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		int Rc_int, Rc_set;

		if (start > end) return;

		index = start;
		J1F(Rc_int, intern->array, index);
		while (Rc_int && index <= end) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
			if (Rc_set == 1) result->counter++;
			J1N(Rc_int, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_INT) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;
			JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
			result->counter++;
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_MIXED) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			zval *new_value;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;
			new_value = emalloc(sizeof(zval));
			ZVAL_COPY(new_value, JUDY_MVAL_READ(PValue));
			JUDY_MVAL_WRITE(PNew, new_value);
			result->counter++;
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_PACKED) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;

			judy_packed_value *src = JUDY_PVAL_READ(PValue);
			if (src) {
				size_t sz = judy_packed_value_size(src);
				judy_packed_value *dst = emalloc(sz);
				memcpy(dst, src, sz);
				JUDY_PVAL_WRITE(PNew, dst);
			} else {
				JUDY_PVAL_WRITE(PNew, NULL);
			}
			result->counter++;
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char *str_start, *str_end;
		size_t str_start_len, str_end_len;

		if (Z_TYPE_P(zstart) != IS_STRING || Z_TYPE_P(zend_val) != IS_STRING) {
			zend_throw_error(zend_ce_type_error, "Judy::slice() expects string arguments for string-keyed arrays");
			return;
		}

		str_start = Z_STRVAL_P(zstart);
		str_start_len = Z_STRLEN_P(zstart);
		str_end = Z_STRVAL_P(zend_val);
		str_end_len = Z_STRLEN_P(zend_val);

		if (strcmp(str_start, str_end) > 0) return;

		{
			uint8_t key[PHP_JUDY_MAX_LENGTH];
			Pvoid_t *PValue;
			int key_len;

			key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
			memcpy(key, str_start, key_len);
			key[key_len] = '\0';

			JSLF(PValue, intern->array, key);
			while (PValue != NULL && PValue != PJERR && strcmp((const char *)key, str_end) <= 0) {
				Pvoid_t *PNew;
				JSLI(PNew, result->array, key);
				if (PNew == PJERR) goto alloc_error;

				if (intern->type == TYPE_STRING_TO_INT) {
					JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				} else {
					zval *new_value;
					new_value = emalloc(sizeof(zval));
					ZVAL_COPY(new_value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(PNew, new_value);
				}
				result->counter++;
				JSLN(PValue, intern->array, key);
			}
		}
	} else if (intern->type == TYPE_STRING_TO_MIXED_HASH) {
		char *str_start, *str_end;
		size_t str_start_len;

		if (Z_TYPE_P(zstart) != IS_STRING || Z_TYPE_P(zend_val) != IS_STRING) {
			zend_throw_error(zend_ce_type_error, "Judy::slice() expects string arguments for string-keyed arrays");
			return;
		}

		str_start = Z_STRVAL_P(zstart);
		str_start_len = Z_STRLEN_P(zstart);
		str_end = Z_STRVAL_P(zend_val);

		if (strcmp(str_start, str_end) > 0) return;

		{
			uint8_t key[PHP_JUDY_MAX_LENGTH];
			Pvoid_t *KValue;
			int key_len;

			key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
			memcpy(key, str_start, key_len);
			key[key_len] = '\0';

			JSLF(KValue, intern->key_index, key);
			while (KValue != NULL && KValue != PJERR && strcmp((const char *)key, str_end) <= 0) {
				Word_t klen = (Word_t)strlen((char *)key);
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, key, klen);
				if (HValue != NULL && HValue != PJERR) {
					Pvoid_t *PNew;
					Pvoid_t *KNew;
					JHSI(PNew, result->array, key, klen);
					if (PNew == PJERR) goto alloc_error;
					zval *new_value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(new_value, JUDY_MVAL_READ(HValue));
					JUDY_MVAL_WRITE(PNew, new_value);
					JSLI(KNew, result->key_index, key);
					if (KNew == PJERR) {
						int jhsd_rc;
						JHSD(jhsd_rc, result->array, key, klen);
						goto alloc_error;
					}
					result->counter++;
				}
				JSLN(KValue, intern->key_index, key);
			}
		}
	} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
		char *str_start, *str_end;
		size_t str_start_len;

		if (Z_TYPE_P(zstart) != IS_STRING || Z_TYPE_P(zend_val) != IS_STRING) {
			zend_throw_error(zend_ce_type_error, "Judy::slice() expects string arguments for string-keyed arrays");
			return;
		}

		str_start = Z_STRVAL_P(zstart);
		str_start_len = Z_STRLEN_P(zstart);
		str_end = Z_STRVAL_P(zend_val);

		if (strcmp(str_start, str_end) > 0) return;

		{
			uint8_t key[PHP_JUDY_MAX_LENGTH];
			Pvoid_t *KValue;
			int key_len;

			key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
			memcpy(key, str_start, key_len);
			key[key_len] = '\0';

			JSLF(KValue, intern->key_index, key);
			while (KValue != NULL && KValue != PJERR && strcmp((const char *)key, str_end) <= 0) {
				Word_t klen = (Word_t)strlen((char *)key);
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, key, klen);
				if (HValue != NULL && HValue != PJERR) {
					Pvoid_t *PNew;
					Pvoid_t *KNew;
					JHSI(PNew, result->array, key, klen);
					if (PNew == PJERR) goto alloc_error;
					JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(HValue));
					JSLI(KNew, result->key_index, key);
					if (KNew == PJERR) {
						int jhsd_rc;
						JHSD(jhsd_rc, result->array, key, klen);
						goto alloc_error;
					}
					result->counter++;
				}
				JSLN(KValue, intern->key_index, key);
			}
		}
	}

	return;

alloc_error:
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during slice", 0);
}
/* }}} */

typedef enum {
	JUDY_COLLECT_ALL,
	JUDY_COLLECT_KEYS,
	JUDY_COLLECT_VALUES
} judy_collect_mode;

/* {{{ Helper to build a PHP array from a Judy array's contents.
   Used by jsonSerialize(), __serialize(), toArray(), keys(), and values(). */
static void judy_populate_array(judy_object *intern, zval *data, judy_collect_mode mode)
{
	if (intern->type == TYPE_BITSET) {
		Word_t index = 0;
		int Rc_int;

		J1F(Rc_int, intern->array, index);
		while (Rc_int) {
			/* BITSET is a set of indices — the index IS the value.
			   keys(), values(), and toArray() all return the same flat index list. */
			add_next_index_long(data, (zend_long)index);
			J1N(Rc_int, intern->array, index);
		}

	} else if (intern->is_integer_keyed) {
		Word_t index = 0;
		Pvoid_t *PValue;

		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			if (mode == JUDY_COLLECT_KEYS) {
				add_next_index_long(data, (zend_long)index);
			} else if (intern->type == TYPE_INT_TO_INT) {
				if (mode == JUDY_COLLECT_ALL) {
					add_index_long(data, (zend_long)index, JUDY_LVAL_READ(PValue));
				} else {
					add_next_index_long(data, JUDY_LVAL_READ(PValue));
				}
			} else if (intern->type == TYPE_INT_TO_PACKED) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (JUDY_LIKELY(packed != NULL)) {
					zval tmp;
					ZVAL_UNDEF(&tmp);
					judy_unpack_value(packed, &tmp);
					if (mode == JUDY_COLLECT_ALL) {
						add_index_zval(data, (zend_long)index, &tmp);
					} else {
						add_next_index_zval(data, &tmp);
					}
				} else {
					if (mode == JUDY_COLLECT_ALL) {
						add_index_null(data, (zend_long)index);
					} else {
						add_next_index_null(data);
					}
				}
			} else { /* TYPE_INT_TO_MIXED */
				if (JUDY_LIKELY(JUDY_MVAL_READ(PValue) != NULL)) {
					zval *value = JUDY_MVAL_READ(PValue);
					Z_TRY_ADDREF_P(value);
					if (mode == JUDY_COLLECT_ALL) {
						add_index_zval(data, (zend_long)index, value);
					} else {
						add_next_index_zval(data, value);
					}
				} else {
					if (mode == JUDY_COLLECT_ALL) {
						add_index_null(data, (zend_long)index);
					} else {
						add_next_index_null(data);
					}
				}
			}
			JLN(PValue, intern->array, index);
		}

	} else { /* is_string_keyed */
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;

		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			if (mode == JUDY_COLLECT_KEYS) {
				add_next_index_string(data, (const char *)key);
			} else {
				Pvoid_t *VValue = PValue;
				if (intern->is_hash_keyed) {
					JHSG(VValue, intern->array, key, (Word_t)strlen((char *)key));
				}

				if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
					if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_INT_HASH) {
						if (mode == JUDY_COLLECT_ALL) {
							add_assoc_long(data, (const char *)key, JUDY_LVAL_READ(VValue));
						} else {
							add_next_index_long(data, JUDY_LVAL_READ(VValue));
						}
					} else { /* MIXED */
						zval *value = JUDY_MVAL_READ(VValue);
						Z_TRY_ADDREF_P(value);
						if (mode == JUDY_COLLECT_ALL) {
							add_assoc_zval(data, (const char *)key, value);
						} else {
							add_next_index_zval(data, value);
						}
					}
				} else {
					if (mode == JUDY_COLLECT_ALL) {
						add_assoc_null(data, (const char *)key);
					} else {
						add_next_index_null(data);
					}
				}
			}

			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		}
	} else if (intern->is_adaptive) {
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		JSLF(PValue, intern->key_index, key);

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			if (mode == JUDY_COLLECT_KEYS) {
				add_next_index_string(data, (const char *)key);
			} else {
				Word_t key_len = (Word_t)strlen((char *)key);
				Word_t sso_idx;
				Pvoid_t *VValue = NULL;

				if (judy_pack_short_string((char *)key, key_len, &sso_idx)) {
					JLG(VValue, intern->array, sso_idx);
				} else {
					JHSG(VValue, intern->hs_array, key, key_len);
				}

				if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
					if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
						if (mode == JUDY_COLLECT_ALL) {
							add_assoc_long(data, (const char *)key, JUDY_LVAL_READ(VValue));
						} else {
							add_next_index_long(data, JUDY_LVAL_READ(VValue));
						}
					} else { /* MIXED */
						zval *value = JUDY_MVAL_READ(VValue);
						Z_TRY_ADDREF_P(value);
						if (mode == JUDY_COLLECT_ALL) {
							add_assoc_zval(data, (const char *)key, value);
						} else {
							add_next_index_zval(data, value);
						}
					}
				} else {
					if (mode == JUDY_COLLECT_ALL) {
						add_assoc_null(data, (const char *)key);
					} else {
						add_next_index_null(data);
					}
				}
			}
			JSLN(PValue, intern->key_index, key);
		}
	}
}

static void judy_build_data_array(judy_object *intern, zval *data)
{
	judy_populate_array(intern, data, JUDY_COLLECT_ALL);
}

/* {{{ proto array Judy::keys()
   Return the keys of the Judy array */
PHP_METHOD(Judy, keys)
{
	JUDY_METHOD_GET_OBJECT
	ZEND_PARSE_PARAMETERS_NONE();
	array_init(return_value);
	judy_populate_array(intern, return_value, JUDY_COLLECT_KEYS);
}
/* }}} */

/* {{{ proto array Judy::values()
   Return the values of the Judy array */
PHP_METHOD(Judy, values)
{
	JUDY_METHOD_GET_OBJECT
	ZEND_PARSE_PARAMETERS_NONE();
	array_init(return_value);
	judy_populate_array(intern, return_value, JUDY_COLLECT_VALUES);
}
/* }}} */

/* {{{ proto int|float Judy::sumValues()
   Return the sum of all values in the Judy array (only for integer-valued types) */
PHP_METHOD(Judy, sumValues)
{
	JUDY_METHOD_GET_OBJECT
	ZEND_PARSE_PARAMETERS_NONE();

	if (intern->type == TYPE_BITSET) {
		Word_t Rc_word;
		J1C(Rc_word, intern->array, 0, -1);
		RETURN_LONG(Rc_word);
	}

	if (intern->type != TYPE_INT_TO_INT && intern->type != TYPE_STRING_TO_INT && 
		intern->type != TYPE_STRING_TO_INT_HASH && intern->type != TYPE_STRING_TO_INT_ADAPTIVE) {
		zend_throw_exception(NULL, "sumValues() is only supported for integer-valued Judy types", 0);
		return;
	}

	double sum = 0;
	if (intern->is_integer_keyed) {
		Word_t index = 0;
		Pvoid_t *PValue;
		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			sum += (double)JUDY_LVAL_READ(PValue);
			JLN(PValue, intern->array, index);
		}
	} else if (intern->is_adaptive) {
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		JSLF(PValue, intern->key_index, key);

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			Word_t key_len = (Word_t)strlen((char *)key);
			Word_t sso_idx;
			Pvoid_t *VValue = NULL;

			if (judy_pack_short_string((char *)key, key_len, &sso_idx)) {
				JLG(VValue, intern->array, sso_idx);
			} else {
				JHSG(VValue, intern->hs_array, key, key_len);
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				sum += (double)JUDY_LVAL_READ(VValue);
			}
			JSLN(PValue, intern->key_index, key);
		}
	} else { /* string keyed */

	if (sum > (double)ZEND_LONG_MAX || sum < (double)ZEND_LONG_MIN) {
		RETURN_DOUBLE(sum);
	} else {
		RETURN_LONG((zend_long)sum);
	}
}
/* }}} */

/* {{{ proto float|null Judy::averageValues()
   Return the average of all values in the Judy array (only for integer-valued types) */
PHP_METHOD(Judy, averageValues)
{
	JUDY_METHOD_GET_OBJECT
	ZEND_PARSE_PARAMETERS_NONE();

	if (intern->counter == 0) {
		RETURN_NULL();
	}

	/* We can't easily call sumValues here, let's just re-implement the loop logic 
	   (or we could extract it, but it's small) */
	
	if (intern->type == TYPE_BITSET) {
		/* For bitset, average is always 1 if count > 0 */
		RETURN_DOUBLE(1.0);
	}

	if (intern->type != TYPE_INT_TO_INT && intern->type != TYPE_STRING_TO_INT && 
		intern->type != TYPE_STRING_TO_INT_HASH && intern->type != TYPE_STRING_TO_INT_ADAPTIVE) {
		zend_throw_exception(NULL, "averageValues() is only supported for integer-valued Judy types", 0);
		return;
	}

	double sum = 0;
	if (intern->is_integer_keyed) {
		Word_t index = 0;
		Pvoid_t *PValue;
		JLF(PValue, intern->array, index);
		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			sum += (double)JUDY_LVAL_READ(PValue);
			JLN(PValue, intern->array, index);
		}
	} else if (intern->is_adaptive) {
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		JSLF(PValue, intern->key_index, key);

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			Word_t key_len = (Word_t)strlen((char *)key);
			Word_t sso_idx;
			Pvoid_t *VValue = NULL;

			if (judy_pack_short_string((char *)key, key_len, &sso_idx)) {
				JLG(VValue, intern->array, sso_idx);
			} else {
				JHSG(VValue, intern->hs_array, key, key_len);
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				sum += (double)JUDY_LVAL_READ(VValue);
			}
			JSLN(PValue, intern->key_index, key);
		}
	} else { /* string keyed */
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			Pvoid_t *VValue = PValue;
			if (intern->is_hash_keyed) {
				JHSG(VValue, intern->array, key, (Word_t)strlen((char *)key));
			}
			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				sum += (double)JUDY_LVAL_READ(VValue);
			}
			if (intern->is_hash_keyed) {
				JSLN(PValue, intern->key_index, key);
			} else {
				JSLN(PValue, intern->array, key);
			}
		}
	}

	RETURN_DOUBLE(sum / (double)intern->counter);
}
/* }}} */

/* {{{ proto int Judy::populationCount(mixed $start = 0, mixed $end = -1)
   Return the number of keys set between $start and $end (inclusive).
   Only supported for integer-keyed types. */
PHP_METHOD(Judy, populationCount)
{
	JUDY_METHOD_GET_OBJECT

	if (!intern->is_integer_keyed) {
		zend_throw_exception(NULL, "populationCount() is only supported for integer-keyed Judy types", 0);
		return;
	}

	zend_long zl_idx1 = 0;
	zend_long zl_idx2 = -1;
	Word_t idx1, idx2;
	Word_t Rc_word;

	ZEND_PARSE_PARAMETERS_START(0, 2)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(zl_idx1)
		Z_PARAM_LONG(zl_idx2)
	ZEND_PARSE_PARAMETERS_END();

	idx1 = (Word_t)zl_idx1;
	idx2 = (Word_t)zl_idx2;

	if (intern->type == TYPE_BITSET) {
		J1C(Rc_word, intern->array, idx1, idx2);
	} else {
		JLC(Rc_word, intern->array, idx1, idx2);
	}

	RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto int Judy::deleteRange(mixed $start, mixed $end)
   Delete all keys between $start and $end (inclusive).
   Returns the number of elements deleted. */
PHP_METHOD(Judy, deleteRange)
{
	JUDY_METHOD_GET_OBJECT
	zend_long deleted = 0;

	if (intern->is_integer_keyed) {
		zend_long zl_start, zl_end;
		Word_t index, index_end;

		ZEND_PARSE_PARAMETERS_START(2, 2)
			Z_PARAM_LONG(zl_start)
			Z_PARAM_LONG(zl_end)
		ZEND_PARSE_PARAMETERS_END();

		index = (Word_t)zl_start;
		index_end = (Word_t)zl_end;

		if (index > index_end) RETURN_LONG(0);

		if (intern->type == TYPE_BITSET) {
			int Rc_int;
			J1F(Rc_int, intern->array, index);
			while (Rc_int && index <= index_end) {
				int Rc_del;
				J1U(Rc_del, intern->array, index);
				if (Rc_del) {
					deleted++;
					intern->counter--;
				}
				/* Re-seek after mutation — J1F is safe after J1U */
				J1F(Rc_int, intern->array, index);
			}
		} else {
			Pvoid_t *PValue;
			JLF(PValue, intern->array, index);
			while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && index <= index_end) {
				int Rc_del;
				if (intern->type == TYPE_INT_TO_MIXED) {
					zval *value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(value);
					efree(value);
				} else if (intern->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *packed = JUDY_PVAL_READ(PValue);
					if (packed) efree(packed);
				}
				JLD(Rc_del, intern->array, index);
				if (Rc_del) {
					deleted++;
					intern->counter--;
				}
				/* Re-seek after mutation — JLF is safe after JLD */
				JLF(PValue, intern->array, index);
			}
		}
	} else if (intern->is_adaptive) {
		char *str_start, *str_end;
		size_t str_start_len, str_end_len;
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;

		ZEND_PARSE_PARAMETERS_START(2, 2)
			Z_PARAM_STRING(str_start, str_start_len)
			Z_PARAM_STRING(str_end, str_end_len)
		ZEND_PARSE_PARAMETERS_END();

		if (strcmp(str_start, str_end) > 0) RETURN_LONG(0);

		size_t key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
		memcpy(key, str_start, key_len);
		key[key_len] = '\0';

		JSLF(PValue, intern->key_index, key);

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && strcmp((const char *)key, str_end) <= 0) {
			Word_t klen = (Word_t)strlen((char *)key);
			Word_t sso_idx;
			int Rc_del;

			if (judy_pack_short_string((char *)key, klen, &sso_idx)) {
				Pvoid_t *VValue;
				JLG(VValue, intern->array, sso_idx);
				if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
					if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
						zval *value = JUDY_MVAL_READ(VValue);
						zval_ptr_dtor(value);
						efree(value);
					}
					JLD(Rc_del, intern->array, sso_idx);
				}
			} else {
				Pvoid_t *VValue;
				JHSG(VValue, intern->hs_array, key, klen);
				if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
					if (intern->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
						zval *value = JUDY_MVAL_READ(VValue);
						zval_ptr_dtor(value);
						efree(value);
					}
					JHSD(Rc_del, intern->hs_array, key, klen);
				}
			}
			
			/* Delete from key_index */
			int Rc_idx_del;
			JSLD(Rc_idx_del, intern->key_index, key);
			if (Rc_idx_del) {
				deleted++;
				intern->counter--;
			}
			
			JSLF(PValue, intern->key_index, key);
		}
	} else { /* string keyed */
		char *str_start, *str_end;
		size_t str_start_len, str_end_len;
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;

		ZEND_PARSE_PARAMETERS_START(2, 2)
			Z_PARAM_STRING(str_start, str_start_len)
			Z_PARAM_STRING(str_end, str_end_len)
		ZEND_PARSE_PARAMETERS_END();

		if (strcmp(str_start, str_end) > 0) RETURN_LONG(0);

		size_t key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
		memcpy(key, str_start, key_len);
		key[key_len] = '\0';

		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && strcmp((const char *)key, str_end) <= 0) {
			if (intern->is_hash_keyed) {
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, key, (Word_t)strlen((char *)key));
				if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
					if (intern->type == TYPE_STRING_TO_MIXED_HASH) {
						zval *value = JUDY_MVAL_READ(HValue);
						zval_ptr_dtor(value);
						efree(value);
					}
					int Rc_del;
					JHSD(Rc_del, intern->array, key, (Word_t)strlen((char *)key));
					(void)Rc_del; /* JUDYERROR_NOTEST: delete cannot partially fail */
				}
				/* Delete from key_index too */
				int Rc_idx_del;
				JSLD(Rc_idx_del, intern->key_index, key);
				if (Rc_idx_del) {
					deleted++;
					intern->counter--;
				}
				/* We must use JSLF again because key_index was modified */
				JSLF(PValue, intern->key_index, key);
			} else {
				if (intern->type == TYPE_STRING_TO_MIXED) {
					zval *value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(value);
					efree(value);
				}
				int Rc_str_del;
				JSLD(Rc_str_del, intern->array, key);
				if (Rc_str_del) {
					deleted++;
					intern->counter--;
				}
				/* We must use JSLF again because array was modified */
				JSLF(PValue, intern->array, key);
			}
		}
	}
	RETURN_LONG(deleted);
}
/* }}} */

/* {{{ proto bool Judy::equals(Judy $other)
   Check if two Judy arrays are identical. */
PHP_METHOD(Judy, equals)
{
	zval *zother;
	judy_object *other;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(zother, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(zother));

	if (intern == other) RETURN_TRUE;
	if (intern->type != other->type) RETURN_FALSE;

	/* Compare counts first */
	Word_t count1, count2;
	if (intern->is_integer_keyed) {
		if (intern->type == TYPE_BITSET) {
			J1C(count1, intern->array, 0, -1);
			J1C(count2, other->array, 0, -1);
		} else {
			JLC(count1, intern->array, 0, -1);
			JLC(count2, other->array, 0, -1);
		}
	} else {
		count1 = (Word_t)intern->counter;
		count2 = (Word_t)other->counter;
	}
	if (count1 != count2) RETURN_FALSE;
	if (count1 == 0) RETURN_TRUE;

	/* Iterate and compare */
	if (intern->is_integer_keyed) {
		Word_t index = 0;
		if (intern->type == TYPE_BITSET) {
			int Rc1, Rc2;
			J1F(Rc1, intern->array, index);
			while (Rc1) {
				J1T(Rc2, other->array, index);
				if (!Rc2) RETURN_FALSE;
				J1N(Rc1, intern->array, index);
			}
		} else {
			Pvoid_t *PVal1, *PVal2;
			JLF(PVal1, intern->array, index);
			while (JUDY_LIKELY(PVal1 != NULL && PVal1 != PJERR)) {
				JLG(PVal2, other->array, index);
				if (PVal2 == NULL || PVal2 == PJERR) RETURN_FALSE;

				if (intern->type == TYPE_INT_TO_INT) {
					if (JUDY_LVAL_READ(PVal1) != JUDY_LVAL_READ(PVal2)) RETURN_FALSE;
				} else if (intern->type == TYPE_INT_TO_MIXED) {
					if (!zend_is_identical(JUDY_MVAL_READ(PVal1), JUDY_MVAL_READ(PVal2))) RETURN_FALSE;
				} else if (intern->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *p1 = JUDY_PVAL_READ(PVal1);
					judy_packed_value *p2 = JUDY_PVAL_READ(PVal2);
					if (p1 == p2) continue;
					if (!p1 || !p2) RETURN_FALSE;
					if (p1->tag != p2->tag) RETURN_FALSE;
					/* Simplified comparison for packed values */
					zval v1, v2;
					judy_unpack_value(p1, &v1);
					judy_unpack_value(p2, &v2);
					int same = zend_is_identical(&v1, &v2);
					zval_ptr_dtor(&v1);
					zval_ptr_dtor(&v2);
					if (!same) RETURN_FALSE;
				}
				JLN(PVal1, intern->array, index);
			}
		}
	} else if (intern->is_adaptive) {
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PVal1;
		key[0] = '\0';
		JSLF(PVal1, intern->key_index, key);

		while (JUDY_LIKELY(PVal1 != NULL && PVal1 != PJERR)) {
			Word_t klen = (Word_t)strlen((char *)key);
			Word_t sso_idx;
			Pvoid_t *V1 = NULL, *V2 = NULL;

			if (judy_pack_short_string((char *)key, klen, &sso_idx)) {
				JLG(V1, intern->array, sso_idx);
			} else {
				JHSG(V1, intern->hs_array, key, klen);
			}

			if (other->is_adaptive) {
				if (judy_pack_short_string((char *)key, klen, &sso_idx)) {
					JLG(V2, other->array, sso_idx);
				} else {
					JHSG(V2, other->hs_array, key, klen);
				}
			} else if (other->is_hash_keyed) {
				JHSG(V2, other->array, key, klen);
			} else {
				JSLG(V2, other->array, key);
			}

			if (!V1 || !V2) RETURN_FALSE;

			if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
				if (JUDY_LVAL_READ(V1) != JUDY_LVAL_READ(V2)) RETURN_FALSE;
			} else {
				if (!zend_is_identical(JUDY_MVAL_READ(V1), JUDY_MVAL_READ(V2))) RETURN_FALSE;
			}

			JSLN(PVal1, intern->key_index, key);
		}
	} else { /* string keyed */
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PVal1, *PVal2;
		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PVal1, intern->key_index, key);
		} else {
			JSLF(PVal1, intern->array, key);
		}

		while (JUDY_LIKELY(PVal1 != NULL && PVal1 != PJERR)) {
			if (intern->is_hash_keyed) {
				Pvoid_t *V1, *V2;
				Word_t key_len = (Word_t)strlen((char *)key);
				JHSG(V1, intern->array, key, key_len);
				JHSG(V2, other->array, key, key_len);
				if (!V1 || !V2) RETURN_FALSE;
				if (intern->type == TYPE_STRING_TO_INT_HASH) {
					if (JUDY_LVAL_READ(V1) != JUDY_LVAL_READ(V2)) RETURN_FALSE;
				} else {
					if (!zend_is_identical(JUDY_MVAL_READ(V1), JUDY_MVAL_READ(V2))) RETURN_FALSE;
				}
			} else {
				JSLG(PVal2, other->array, key);
				if (!PVal2) RETURN_FALSE;
				if (intern->type == TYPE_STRING_TO_INT) {
					if (JUDY_LVAL_READ(PVal1) != JUDY_LVAL_READ(PVal2)) RETURN_FALSE;
				} else {
					if (!zend_is_identical(JUDY_MVAL_READ(PVal1), JUDY_MVAL_READ(PVal2))) RETURN_FALSE;
				}
			}

			if (intern->is_hash_keyed) {
				JSLN(PVal1, intern->key_index, key);
			} else {
				JSLN(PVal1, intern->array, key);
			}
		}
	}

	RETURN_TRUE;
}
/* }}} */

typedef int (*judy_callback_action)(judy_object *intern, zval *key, zval *value, void *data);

static void judy_callback_iterator(judy_object *intern, zend_fcall_info *fci, zend_fcall_info_cache *fci_cache, judy_callback_action action, void *data)
{
	zval args[2];
	zval retval;
	int action_rc = SUCCESS;

	if (intern->is_integer_keyed) {
		Word_t index = 0;
		if (intern->type == TYPE_BITSET) {
			int Rc_int;
			J1F(Rc_int, intern->array, index);
			while (Rc_int && action_rc == SUCCESS) {
				ZVAL_LONG(&args[1], (zend_long)index);
				ZVAL_BOOL(&args[0], 1);
				
				fci->param_count = 2;
				fci->params = args;
				fci->retval = &retval;

				if (zend_call_function(fci, fci_cache) == SUCCESS && !Z_ISUNDEF(retval)) {
					action_rc = action(intern, &args[1], &retval, data);
					zval_ptr_dtor(&retval);
				} else {
					action_rc = FAILURE;
				}
				if (action_rc == SUCCESS) J1N(Rc_int, intern->array, index);
			}
		} else {
			Pvoid_t *PValue;
			JLF(PValue, intern->array, index);
			while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && action_rc == SUCCESS) {
				ZVAL_LONG(&args[1], (zend_long)index);
				if (intern->type == TYPE_INT_TO_INT) {
					ZVAL_LONG(&args[0], JUDY_LVAL_READ(PValue));
				} else if (intern->type == TYPE_INT_TO_MIXED) {
					ZVAL_COPY(&args[0], JUDY_MVAL_READ(PValue));
				} else { /* PACKED */
					ZVAL_UNDEF(&args[0]);
					judy_unpack_value(JUDY_PVAL_READ(PValue), &args[0]);
				}

				fci->param_count = 2;
				fci->params = args;
				fci->retval = &retval;

				if (zend_call_function(fci, fci_cache) == SUCCESS && !Z_ISUNDEF(retval)) {
					action_rc = action(intern, &args[1], &retval, data);
					zval_ptr_dtor(&retval);
				} else {
					action_rc = FAILURE;
				}
				zval_ptr_dtor(&args[0]);
				if (action_rc == SUCCESS) JLN(PValue, intern->array, index);
			}
		}
	} else { /* string keyed */
		uint8_t *key = intern->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		if (intern->is_hash_keyed) {
			JSLF(PValue, intern->key_index, key);
		} else {
			JSLF(PValue, intern->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && action_rc == SUCCESS) {
			ZVAL_STRING(&args[1], (const char *)key);
			Pvoid_t *VValue = PValue;
			if (intern->is_hash_keyed) {
				JHSG(VValue, intern->array, key, (Word_t)strlen((char *)key));
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
				if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_INT_HASH) {
					ZVAL_LONG(&args[0], JUDY_LVAL_READ(VValue));
				} else {
					ZVAL_COPY(&args[0], JUDY_MVAL_READ(VValue));
				}

				fci->param_count = 2;
				fci->params = args;
				fci->retval = &retval;

				if (zend_call_function(fci, fci_cache) == SUCCESS && !Z_ISUNDEF(retval)) {
					action_rc = action(intern, &args[1], &retval, data);
					zval_ptr_dtor(&retval);
				} else {
					action_rc = FAILURE;
				}
				zval_ptr_dtor(&args[0]);
			}
			zval_ptr_dtor(&args[1]);

			if (action_rc == SUCCESS) {
				if (intern->is_hash_keyed) {
					JSLN(PValue, intern->key_index, key);
				} else {
					JSLN(PValue, intern->array, key);
				}
			}
			}
			}
			} else if (intern->is_adaptive) {
			uint8_t *key = intern->key_scratch;
			Pvoid_t *PValue;
			key[0] = '\0';
			JSLF(PValue, intern->key_index, key);

			while (JUDY_LIKELY(PValue != NULL && PValue != PJERR) && action_rc == SUCCESS) {
			ZVAL_STRING(&args[1], (const char *)key);
			Word_t key_len = (Word_t)strlen((char *)key);
			Word_t sso_idx;
			Pvoid_t *VValue = NULL;

			if (judy_pack_short_string((char *)key, key_len, &sso_idx)) {
			JLG(VValue, intern->array, sso_idx);
			} else {
			JHSG(VValue, intern->hs_array, key, key_len);
			}

			if (JUDY_LIKELY(VValue != NULL && VValue != PJERR)) {
			if (intern->type == TYPE_STRING_TO_INT_ADAPTIVE) {
				ZVAL_LONG(&args[0], JUDY_LVAL_READ(VValue));
			} else {
				ZVAL_COPY(&args[0], JUDY_MVAL_READ(VValue));
			}

			fci->param_count = 2;
			fci->params = args;
			fci->retval = &retval;

			if (zend_call_function(fci, fci_cache) == SUCCESS && !Z_ISUNDEF(retval)) {
				action_rc = action(intern, &args[1], &retval, data);
				zval_ptr_dtor(&retval);
			} else {
				action_rc = FAILURE;
			}
			zval_ptr_dtor(&args[0]);
			}
			zval_ptr_dtor(&args[1]);

			if (action_rc == SUCCESS) {
			JSLN(PValue, intern->key_index, key);
			}
			}
			}
			}
static int action_foreach(judy_object *intern, zval *key, zval *retval, void *data) {
	return SUCCESS; /* forEach just calls the callback, iterator handles it */
}

/* {{{ proto void Judy::forEach(callable $callback) */
PHP_METHOD(Judy, forEach)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fci_cache;
	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fci_cache)
	ZEND_PARSE_PARAMETERS_END();

	judy_callback_iterator(intern, &fci, &fci_cache, action_foreach, NULL);
}
/* }}} */

static int action_filter(judy_object *intern, zval *key, zval *retval, void *data) {
	judy_object *result = (judy_object *)data;
	if (zend_is_true(retval)) {
		/* We need to re-fetch the value from 'intern' to put it into 'result'
		   because 'retval' is the callback result (bool), not the original value. */
		zval value;
		judy_object_read_dimension_helper_zv(intern, key, &value);
		judy_object_write_dimension_helper_zv(result, key, &value);
		zval_ptr_dtor(&value);
	}
	return SUCCESS;
}

/* {{{ proto Judy Judy::filter(callable $predicate) */
PHP_METHOD(Judy, filter)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fci_cache;
	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fci_cache)
	ZEND_PARSE_PARAMETERS_END();

	judy_object *result = judy_create_result(return_value, intern->type);
	judy_callback_iterator(intern, &fci, &fci_cache, action_filter, result);
}
/* }}} */

static int action_map(judy_object *intern, zval *key, zval *retval, void *data) {
	judy_object *result = (judy_object *)data;
	/* retval is the mapped value. We put it into the result at the same key. */
	judy_object_write_dimension_helper_zv(result, key, retval);
	return SUCCESS;
}

/* {{{ proto Judy Judy::map(callable $transform) */
PHP_METHOD(Judy, map)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fci_cache;
	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fci_cache)
	ZEND_PARSE_PARAMETERS_END();

	/* We default to same type, but map might transform values to types incompatible 
	   with current Judy type (e.g. mapping ints to strings in INT_TO_INT).
	   The write helper will handle errors or we could use INT_TO_MIXED if we want to be safe. 
	   Let's stick to same type for now as proposed. */
	judy_object *result = judy_create_result(return_value, intern->type);
	judy_callback_iterator(intern, &fci, &fci_cache, action_map, result);
}
/* }}} */

static void judy_object_merge_with_helper(judy_object *intern, judy_object *other)
{
	Word_t index = 0;
	if (other->is_integer_keyed) {
		if (other->type == TYPE_BITSET) {
			int Rc_int;
			J1F(Rc_int, other->array, index);
			while (Rc_int) {
				if (intern->type == TYPE_BITSET) {
					int Rc_set;
					J1S(Rc_set, intern->array, index);
					if (Rc_set == 1) intern->counter++;
				} else {
					zval val, zidx;
					ZVAL_BOOL(&val, 1);
					ZVAL_LONG(&zidx, (zend_long)index);
					judy_object_write_dimension_helper_zv(intern, &zidx, &val);
				}
				J1N(Rc_int, other->array, index);
			}
		} else {
			Pvoid_t *PValue;
			JLF(PValue, other->array, index);
			while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
				zval zidx, zval_val;
				ZVAL_LONG(&zidx, (zend_long)index);
				ZVAL_UNDEF(&zval_val);
				judy_object_read_dimension_helper_zv(other, &zidx, &zval_val);
				judy_object_write_dimension_helper_zv(intern, &zidx, &zval_val);
				zval_ptr_dtor(&zval_val);
				JLN(PValue, other->array, index);
			}
		}
	} else { /* string keyed */
		uint8_t *key = other->key_scratch;
		Pvoid_t *PValue;
		key[0] = '\0';
		if (other->is_hash_keyed) {
			JSLF(PValue, other->key_index, key);
		} else {
			JSLF(PValue, other->array, key);
		}

		while (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
			zval zkey, zval_val;
			ZVAL_STRING(&zkey, (const char *)key);
			ZVAL_UNDEF(&zval_val);
			judy_object_read_dimension_helper_zv(other, &zkey, &zval_val);
			judy_object_write_dimension_helper_zv(intern, &zkey, &zval_val);
			zval_ptr_dtor(&zval_val);
			zval_ptr_dtor(&zkey);

			if (other->is_hash_keyed) {
				JSLN(PValue, other->key_index, key);
			} else {
				JSLN(PValue, other->array, key);
			}
		}
	}
}

/* {{{ proto void Judy::mergeWith(Judy $other)
   Merge another Judy array into this one (in-place). Overwrites existing keys. */
PHP_METHOD(Judy, mergeWith)
{
	zval *zother;
	judy_object *other;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(zother, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(zother));

	if (intern == other) return;

	if (intern->is_integer_keyed != other->is_integer_keyed) {
		zend_throw_exception(NULL, "Cannot merge Judy arrays with incompatible key types (integer vs string)", 0);
		return;
	}

	judy_object_merge_with_helper(intern, other);
}
/* }}} */

/* {{{ proto mixed Judy::jsonSerialize()
   Returns data suitable for json_encode(). Implements JsonSerializable. */
PHP_METHOD(Judy, jsonSerialize)
{
	JUDY_METHOD_GET_OBJECT

	array_init(return_value);
	judy_build_data_array(intern, return_value);
}
/* }}} */

/* {{{ proto array Judy::__serialize()
   Returns serialization data: ['type' => int, 'data' => array] */
PHP_METHOD(Judy, __serialize)
{
	JUDY_METHOD_GET_OBJECT

	zval data;

	array_init(return_value);
	add_assoc_long(return_value, "type", intern->type);

	array_init(&data);
	judy_build_data_array(intern, &data);
	add_assoc_zval(return_value, "data", &data);
}
/* }}} */

/* Forward declaration — defined after fromArray()/putAll() */
static void judy_populate_from_array(zval *judy_obj, zval *arr);

/* {{{ proto void Judy::__unserialize(array $data)
   Restores a Judy array from serialized data */
PHP_METHOD(Judy, __unserialize)
{
	zval *arr, *ztype, *zdata;
	zend_long type;
	judy_type jtype;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	ztype = zend_hash_str_find(Z_ARRVAL_P(arr), "type", sizeof("type") - 1);
	zdata = zend_hash_str_find(Z_ARRVAL_P(arr), "data", sizeof("data") - 1);

	if (!ztype || !zdata || Z_TYPE_P(ztype) != IS_LONG || Z_TYPE_P(zdata) != IS_ARRAY) {
		zend_throw_exception(NULL, "Invalid serialization data for Judy array", 0);
		return;
	}

	type = Z_LVAL_P(ztype);
	JTYPE(jtype, type);
	if (jtype == 0) {
		zend_throw_exception(NULL, "Invalid Judy type in serialized data", 0);
		return;
	}

	intern->array = (Pvoid_t) NULL;
	intern->counter = 0;
	intern->key_index = (Pvoid_t) NULL;
	intern->hs_array = (Pvoid_t) NULL;
	judy_init_type_flags(intern, jtype);

	judy_populate_from_array(object, zdata);
}
/* }}} */

/* {{{ proto array Judy::toArray()
   Convert the Judy array to a native PHP array */
PHP_METHOD(Judy, toArray)
{
	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_NONE();

	array_init(return_value);
	judy_build_data_array(intern, return_value);
}
/* }}} */

/* {{{ judy_populate_from_array — shared helper for fromArray(), putAll(), __unserialize().
   Type-specialized tight loops for integer-keyed types avoid per-element
   write_dimension_helper dispatch. String-keyed types still use the helper
   (key validation + embedded NUL checks + dual JudySL/JudyHS insert). */
static void judy_populate_from_array(zval *judy_obj, zval *arr) {
	judy_object *intern = php_judy_object(Z_OBJ_P(judy_obj));
	zval *entry;
	zend_string *str_key;
	zend_ulong num_key;

	switch (intern->type) {
	case TYPE_BITSET:
	{
		/* BITSET data is a flat array of index values */
		int Rc_int;
		ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(arr), entry) {
			Word_t index = (Word_t)zval_get_long(entry);
			J1S(Rc_int, intern->array, index);
			if (Rc_int == 1) intern->counter++;
		} ZEND_HASH_FOREACH_END();
		break;
	}
	case TYPE_INT_TO_INT:
	{
		Pvoid_t *PValue;
		ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(arr), num_key, str_key, entry) {
			Word_t index = (Word_t)num_key;
			zend_long lval = zval_get_long(entry); /* evaluate before JLI to prevent UAF via callbacks */
			Pvoid_t *PExisting;
			JLG(PExisting, intern->array, index);
			JLI(PValue, intern->array, index);
			if (PValue != NULL && PValue != PJERR) {
				JUDY_LVAL_WRITE(PValue, lval);
				if (PExisting == NULL) intern->counter++;
			}
		} ZEND_HASH_FOREACH_END();
		break;
	}
	case TYPE_INT_TO_MIXED:
	{
		Pvoid_t *PValue;
		ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(arr), num_key, str_key, entry) {
			Word_t index = (Word_t)num_key;
			JLI(PValue, intern->array, index);
			if (PValue != NULL && PValue != PJERR) {
				if (*(Pvoid_t *)PValue != NULL) {
					zval *old_value = JUDY_MVAL_READ(PValue);
					zval_ptr_dtor(old_value);
					efree(old_value);
				} else {
					intern->counter++;
				}
				zval *new_value = emalloc(sizeof(zval));
				ZVAL_COPY(new_value, entry);
				JUDY_MVAL_WRITE(PValue, new_value);
			}
		} ZEND_HASH_FOREACH_END();
		break;
	}
	case TYPE_INT_TO_PACKED:
	{
		Pvoid_t *PValue;
		ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(arr), num_key, str_key, entry) {
			Word_t index = (Word_t)num_key;
			judy_packed_value *packed = judy_pack_value(entry);
			if (!packed) continue;
			JLI(PValue, intern->array, index);
			if (PValue != NULL && PValue != PJERR) {
				if (*(Pvoid_t *)PValue != NULL) {
					judy_packed_value *old = JUDY_PVAL_READ(PValue);
					if (old) efree(old);
				} else {
					intern->counter++;
				}
				JUDY_PVAL_WRITE(PValue, packed);
			} else {
				efree(packed);
			}
		} ZEND_HASH_FOREACH_END();
		break;
	}
	default:
	{
		/* String-keyed types: keep write_dimension_helper for key validation */
		zval offset;
		ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(arr), num_key, str_key, entry) {
			if (str_key) {
				ZVAL_STR_COPY(&offset, str_key);
			} else {
				ZVAL_STR(&offset, zend_long_to_str((zend_long)num_key));
			}
			judy_object_write_dimension_helper(judy_obj, &offset, entry);
			zval_ptr_dtor(&offset);
		} ZEND_HASH_FOREACH_END();
		break;
	}
	}
}
/* }}} */

/* {{{ proto Judy Judy::fromArray(int $type, array $data)
   Static factory: create a new Judy array from a PHP array */
PHP_METHOD(Judy, fromArray)
{
	zend_long type;
	judy_type jtype;
	zval *arr;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(type)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	JTYPE(jtype, type);
	if (jtype == 0) {
		zend_throw_exception(NULL, "Invalid Judy type", 0);
		return;
	}

	judy_create_result(return_value, jtype);
	judy_populate_from_array(return_value, arr);
}
/* }}} */

/* {{{ proto void Judy::putAll(array $data)
   Bulk-insert entries from a PHP array into this Judy array */
PHP_METHOD(Judy, putAll)
{
	zval *arr;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	judy_populate_from_array(object, arr);
}
/* }}} */

/* {{{ proto array Judy::getAll(array $keys)
   Retrieve multiple values at once. Returns key => value (or null if absent). */
PHP_METHOD(Judy, getAll)
{
	zval *keys, *key_entry;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(keys)
	ZEND_PARSE_PARAMETERS_END();

	array_init(return_value);

	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys), key_entry) {
		if (intern->is_integer_keyed) {
			Word_t index = (Word_t)zval_get_long(key_entry);
			if (intern->type == TYPE_BITSET) {
				int Rc_int;
				J1T(Rc_int, intern->array, index);
				add_index_bool(return_value, (zend_long)index, Rc_int);
			} else {
				Pvoid_t *PValue;
				JLG(PValue, intern->array, index);
				if (JUDY_UNLIKELY(PValue == NULL || PValue == PJERR)) {
					add_index_null(return_value, (zend_long)index);
				} else if (intern->type == TYPE_INT_TO_INT) {
					add_index_long(return_value, (zend_long)index, JUDY_LVAL_READ(PValue));
				} else if (intern->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *packed = JUDY_PVAL_READ(PValue);
					if (JUDY_LIKELY(packed != NULL)) {
						zval tmp;
						ZVAL_UNDEF(&tmp);
						judy_unpack_value(packed, &tmp);
						add_index_zval(return_value, (zend_long)index, &tmp);
					} else {
						add_index_null(return_value, (zend_long)index);
					}
				} else { /* TYPE_INT_TO_MIXED */
					if (JUDY_LIKELY(JUDY_MVAL_READ(PValue) != NULL)) {
						zval *value = JUDY_MVAL_READ(PValue);
						Z_TRY_ADDREF_P(value);
						add_index_zval(return_value, (zend_long)index, value);
					} else {
						add_index_null(return_value, (zend_long)index);
					}
				}
			}
		} else { /* is_string_keyed */
			zend_string *skey = zval_get_string(key_entry);
			if (intern->is_hash_keyed) {
				Pvoid_t *HValue;
				JHSG(HValue, intern->array, (uint8_t *)ZSTR_VAL(skey), (Word_t)ZSTR_LEN(skey));
				if (JUDY_UNLIKELY(HValue == NULL || HValue == PJERR)) {
					add_assoc_null(return_value, ZSTR_VAL(skey));
				} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
					add_assoc_long(return_value, ZSTR_VAL(skey), JUDY_LVAL_READ(HValue));
				} else if (JUDY_LIKELY(JUDY_MVAL_READ(HValue) != NULL)) {
					zval *value = JUDY_MVAL_READ(HValue);
					Z_TRY_ADDREF_P(value);
					add_assoc_zval(return_value, ZSTR_VAL(skey), value);
				} else {
					add_assoc_null(return_value, ZSTR_VAL(skey));
				}
			} else {
				Pvoid_t *PValue;
				JSLG(PValue, intern->array, (uint8_t *)ZSTR_VAL(skey));
				if (JUDY_UNLIKELY(PValue == NULL || PValue == PJERR)) {
					add_assoc_null(return_value, ZSTR_VAL(skey));
				} else if (intern->type == TYPE_STRING_TO_INT) {
					add_assoc_long(return_value, ZSTR_VAL(skey), JUDY_LVAL_READ(PValue));
				} else { /* TYPE_STRING_TO_MIXED */
					if (JUDY_LIKELY(JUDY_MVAL_READ(PValue) != NULL)) {
						zval *value = JUDY_MVAL_READ(PValue);
						Z_TRY_ADDREF_P(value);
						add_assoc_zval(return_value, ZSTR_VAL(skey), value);
					} else {
						add_assoc_null(return_value, ZSTR_VAL(skey));
					}
				}
			}
			zend_string_release(skey);
		}
	} ZEND_HASH_FOREACH_END();
}
/* }}} */

/* {{{ proto int Judy::increment(mixed $key, int $amount = 1)
   Atomic increment for INT_TO_INT (single-traversal via JLI) and
   STRING_TO_INT (two traversals: JSLG for counter tracking + JSLI).
   Returns the new value. Creates the key with value $amount if it doesn't exist. */
PHP_METHOD(Judy, increment)
{
	zval *zkey;
	zend_long amount = 1;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(zkey)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(amount)
	ZEND_PARSE_PARAMETERS_END();

	if (intern->type == TYPE_INT_TO_INT) {
		Word_t index = (Word_t)zval_get_long(zkey);
		Pvoid_t *PValue;
		Pvoid_t *PExisting;

		JLG(PExisting, intern->array, index);
		JLI(PValue, intern->array, index);
		if (PValue == NULL || PValue == PJERR) {
			zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
			return;
		}
		if (PExisting == NULL) intern->counter++;
		zend_long old_val = JUDY_LVAL_READ(PValue);
		JUDY_LVAL_WRITE(PValue, old_val + amount);
		RETURN_LONG(old_val + amount);

	} else if (intern->type == TYPE_STRING_TO_INT) {
		zend_string *skey = zval_get_string(zkey);
		Pvoid_t *PExisting;
		Pvoid_t *PValue;

		if (ZSTR_LEN(skey) >= PHP_JUDY_MAX_LENGTH) {
			zend_string_release(skey);
			zend_throw_exception_ex(NULL, 0,
				"Judy string key length (%zu) exceeds maximum of %d bytes",
				ZSTR_LEN(skey), PHP_JUDY_MAX_LENGTH - 1);
			return;
		}

		/* Check if key exists for counter tracking (requires JSLG + JSLI) */
		JSLG(PExisting, intern->array, (uint8_t *)ZSTR_VAL(skey));

		JSLI(PValue, intern->array, (uint8_t *)ZSTR_VAL(skey));
		if (PValue == NULL || PValue == PJERR) {
			zend_string_release(skey);
			zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
			return;
		}

		if (PExisting == NULL) {
			intern->counter++;
		}

		zend_long old_val = JUDY_LVAL_READ(PValue);
		JUDY_LVAL_WRITE(PValue, old_val + amount);
		zend_string_release(skey);
		RETURN_LONG(old_val + amount);

	} else if (intern->type == TYPE_STRING_TO_INT_HASH) {
		zend_string *skey = zval_get_string(zkey);
		Pvoid_t *HExisting;
		Pvoid_t *HValue;
		Word_t key_len = (Word_t)ZSTR_LEN(skey);

		if (ZSTR_LEN(skey) >= PHP_JUDY_MAX_LENGTH) {
			zend_string_release(skey);
			zend_throw_exception_ex(NULL, 0,
				"Judy string key length (%zu) exceeds maximum of %d bytes",
				ZSTR_LEN(skey), PHP_JUDY_MAX_LENGTH - 1);
			return;
		}

		if (memchr(ZSTR_VAL(skey), '\0', ZSTR_LEN(skey)) != NULL) {
			zend_string_release(skey);
			zend_throw_exception(NULL,
				"Judy STRING_TO_INT_HASH keys must not contain embedded null bytes", 0);
			return;
		}

		/* Check if key exists for counter tracking */
		JHSG(HExisting, intern->array, (uint8_t *)ZSTR_VAL(skey), key_len);

		JHSI(HValue, intern->array, (uint8_t *)ZSTR_VAL(skey), key_len);
		if (HValue == NULL || HValue == PJERR) {
			zend_string_release(skey);
			zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
			return;
		}

		if (HExisting == NULL) {
			/* New key — register in key_index for iteration */
			Pvoid_t *KValue;
			JSLI(KValue, intern->key_index, (uint8_t *)ZSTR_VAL(skey));
			if (KValue == PJERR) {
				int Rc_tmp = 0;
				JHSD(Rc_tmp, intern->array, (uint8_t *)ZSTR_VAL(skey), key_len);
				zend_string_release(skey);
				zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
				return;
			}
			intern->counter++;
		}

		zend_long old_val = JUDY_LVAL_READ(HValue);
		JUDY_LVAL_WRITE(HValue, old_val + amount);
		zend_string_release(skey);
		RETURN_LONG(old_val + amount);

	} else {
		zend_throw_exception(NULL, "Judy::increment() is only supported for INT_TO_INT, STRING_TO_INT and STRING_TO_INT_HASH types", 0);
		return;
	}
}
/* }}} */

/* {{{ proto int Judy::getType()
   Return the current Judy Array type */
PHP_METHOD(Judy, getType)
{
	JUDY_METHOD_GET_OBJECT
	RETURN_LONG(intern->type);
}
/* }}} */

/* {{{ proto string judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
	RETURN_STRING(PHP_JUDY_VERSION);
}
/* }}} */

/* {{{ proto int judy_type(Judy array)
   Return the php judy type for the given array */
PHP_FUNCTION(judy_type)
{
	zval *object;
	judy_object *array;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(object, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	array = php_judy_object(Z_OBJ_P(object));
	RETURN_LONG(array->type);
}
/* }}} */


static const zend_module_dep judy_deps[] = {
	ZEND_MOD_REQUIRED("spl")
	ZEND_MOD_REQUIRED("json")
	{NULL, NULL, NULL}
};

/* {{{ judy_module_entry
*/
zend_module_entry judy_module_entry = {
	STANDARD_MODULE_HEADER_EX, NULL,
	judy_deps,
	PHP_JUDY_EXTNAME,
	ext_functions,
	PHP_MINIT(judy),
	PHP_MSHUTDOWN(judy),
	PHP_RINIT(judy),
	NULL,
	PHP_MINFO(judy),
	PHP_JUDY_VERSION,
	STANDARD_MODULE_PROPERTIES
};
/* }}} */

#ifdef COMPILE_DL_JUDY
ZEND_GET_MODULE(judy)
#endif

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

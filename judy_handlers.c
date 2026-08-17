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
#include "judy_handlers.h"

/* {{{ judy_object_clone
*/
zend_object *judy_object_clone(zend_object *this_ptr)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = php_judy_object(this_ptr);

	judy_object_new_ex(old_obj->std.ce, &new_obj);

	/* new Judy array to populate */
	Pvoid_t newJArray = (Pvoid_t) NULL;

	zend_objects_clone_members(&new_obj->std, &old_obj->std);

	if (old_obj->type == TYPE_BITSET) {
		/* Cloning Judy1 Array */

		/* Key/index */
		Word_t  kindex = 0;

		/* Insert return value */
		int     Rc_int = 0;

		J1F(Rc_int, old_obj->array, kindex);
		while (Rc_int == 1)
		{
			int Rc_ins;
			J1S(Rc_ins, newJArray, kindex);
			/* Check the insert before J1N clobbers Rc_int: on allocation
			 * failure (JERR) stop rather than silently dropping the bit and
			 * producing a partial clone. */
			if (Rc_ins == JERR) break;
			J1N(Rc_int, old_obj->array, kindex);
		}
	} else if (old_obj->type == TYPE_INT_TO_INT || old_obj->type == TYPE_INT_TO_MIXED
			|| old_obj->type == TYPE_INT_TO_PACKED) {
		/* Cloning JudyL Array */

		/* Key/index */
		Word_t kindex = 0;

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		JLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JLI(newPValue, newJArray, kindex);
			if (newPValue != NULL && newPValue != PJERR) {
				if (old_obj->type == TYPE_INT_TO_MIXED) {
					zval *value = emalloc(sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(newPValue, value);
				} else if (old_obj->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *src = JUDY_PVAL_READ(PValue);
					if (src) {
						size_t sz = judy_packed_value_size(src);
						judy_packed_value *dst = emalloc(sz);
						memcpy(dst, src, sz);
						JUDY_PVAL_WRITE(newPValue, dst);
					} else {
						JUDY_PVAL_WRITE(newPValue, NULL);
					}
				} else {
					JUDY_LVAL_WRITE(newPValue, JUDY_LVAL_READ(PValue));
				}
			}
			JLN(PValue, old_obj->array, kindex)
		}
	} else if (old_obj->type == TYPE_STRING_TO_INT || old_obj->type == TYPE_STRING_TO_MIXED) {
		/* Cloning JudySL Array */

		/* Key/index */
		uint8_t *kindex = old_obj->key_scratch;

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		/* smallest string is a null-terminated character */
		kindex[0] = '\0';

		JSLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JSLI(newPValue, newJArray, kindex);
			if (JUDY_LIKELY(newPValue != NULL && newPValue != PJERR)) {
				if (old_obj->type == TYPE_STRING_TO_MIXED) {
					zval *value = emalloc(sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(newPValue, value);
				} else {
					JUDY_LVAL_WRITE(newPValue, JUDY_LVAL_READ(PValue));
				}
			}
			JSLN(PValue, old_obj->array, kindex)
		}
	} else if (old_obj->type == TYPE_STRING_TO_MIXED_HASH) {
		/* Cloning JudyHS Array + parallel JudySL key_index */

		uint8_t *kindex = old_obj->key_scratch;
		Pvoid_t *KValue;
		Pvoid_t newKeyIndex = (Pvoid_t) NULL;

		kindex[0] = '\0';
		JSLF(KValue, old_obj->key_index, kindex);
		while (JUDY_LIKELY(KValue != NULL && KValue != PJERR)) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			Pvoid_t *HValue;
			JHSG(HValue, old_obj->array, kindex, klen);
			if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
				Pvoid_t *newHValue;
				JHSI(newHValue, newJArray, kindex, klen);
				if (JUDY_LIKELY(newHValue != NULL && newHValue != PJERR)) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(HValue));
					JUDY_MVAL_WRITE(newHValue, value);
					/* Register the key only after the value is in place, so
					 * the value store and key_index never diverge. Roll the
					 * value back (freeing the zval) if the key insert fails. */
					Pvoid_t *newKValue;
					JSLI(newKValue, newKeyIndex, kindex);
					if (JUDY_UNLIKELY(newKValue == PJERR)) {
						int rc;
						zval_ptr_dtor(value);
						efree(value);
						JHSD(rc, newJArray, kindex, klen);
						break;
					}
				}
			}
			JSLN(KValue, old_obj->key_index, kindex)
		}
		new_obj->key_index = newKeyIndex;
	} else if (old_obj->type == TYPE_STRING_TO_INT_HASH) {
		/* Cloning JudyHS Array (Word_t values) + parallel JudySL key_index */

		uint8_t *kindex = old_obj->key_scratch;
		Pvoid_t *KValue;
		Pvoid_t newKeyIndex = (Pvoid_t) NULL;

		kindex[0] = '\0';
		JSLF(KValue, old_obj->key_index, kindex);
		while (JUDY_LIKELY(KValue != NULL && KValue != PJERR)) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			Pvoid_t *HValue;
			JHSG(HValue, old_obj->array, kindex, klen);
			if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
				Pvoid_t *newHValue;
				JHSI(newHValue, newJArray, kindex, klen);
				if (JUDY_LIKELY(newHValue != NULL && newHValue != PJERR)) {
					JUDY_LVAL_WRITE(newHValue, JUDY_LVAL_READ(HValue));
					/* Register the key only after the value is in place. */
					Pvoid_t *newKValue;
					JSLI(newKValue, newKeyIndex, kindex);
					if (JUDY_UNLIKELY(newKValue == PJERR)) {
						int rc;
						JHSD(rc, newJArray, kindex, klen);
						break;
					}
				}
			}
			JSLN(KValue, old_obj->key_index, kindex)
		}
		new_obj->key_index = newKeyIndex;
	} else if (old_obj->type == TYPE_STRING_TO_MIXED_ADAPTIVE
			|| old_obj->type == TYPE_STRING_TO_INT_ADAPTIVE) {
		/* Cloning Adaptive Array: JudyL (SSO) + JudyHS (long) + JudySL key_index */

		uint8_t *kindex = old_obj->key_scratch;
		Pvoid_t *KValue;
		Pvoid_t newKeyIndex = (Pvoid_t) NULL;
		Pvoid_t newHsArray = (Pvoid_t) NULL;

		kindex[0] = '\0';
		JSLF(KValue, old_obj->key_index, kindex);
		while (JUDY_LIKELY(KValue != NULL && KValue != PJERR)) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			Word_t sso_idx;
			int value_ok = 0;      /* value slot successfully written */
			int is_sso = 0;        /* which store to roll back on key failure */
			zval *copied = NULL;   /* MIXED: the zval to free on rollback */

			if (judy_pack_short_string_internal((char *)kindex, klen, &sso_idx)) {
				/* Short key — stored in JudyL (intern->array) */
				is_sso = 1;
				Pvoid_t *PValue;
				JLG(PValue, old_obj->array, sso_idx);
				if (JUDY_LIKELY(PValue != NULL && PValue != PJERR)) {
					Pvoid_t *newPValue;
					JLI(newPValue, newJArray, sso_idx);
					if (JUDY_LIKELY(newPValue != NULL && newPValue != PJERR)) {
						if (old_obj->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
							copied = emalloc(sizeof(zval));
							ZVAL_COPY(copied, JUDY_MVAL_READ(PValue));
							JUDY_MVAL_WRITE(newPValue, copied);
						} else {
							JUDY_LVAL_WRITE(newPValue, JUDY_LVAL_READ(PValue));
						}
						value_ok = 1;
					}
				}
			} else {
				/* Long key — stored in JudyHS (intern->hs_array) */
				Pvoid_t *HValue;
				JHSG(HValue, old_obj->hs_array, kindex, klen);
				if (JUDY_LIKELY(HValue != NULL && HValue != PJERR)) {
					Pvoid_t *newHValue;
					JHSI(newHValue, newHsArray, kindex, klen);
					if (JUDY_LIKELY(newHValue != NULL && newHValue != PJERR)) {
						if (old_obj->type == TYPE_STRING_TO_MIXED_ADAPTIVE) {
							copied = emalloc(sizeof(zval));
							ZVAL_COPY(copied, JUDY_MVAL_READ(HValue));
							JUDY_MVAL_WRITE(newHValue, copied);
						} else {
							JUDY_LVAL_WRITE(newHValue, JUDY_LVAL_READ(HValue));
						}
						value_ok = 1;
					}
				}
			}

			/* Register the key only after its value is in place; on key-insert
			 * failure roll the value back so the stores never diverge. */
			if (value_ok) {
				Pvoid_t *newKValue;
				JSLI(newKValue, newKeyIndex, kindex);
				if (JUDY_UNLIKELY(newKValue == PJERR)) {
					int rc;
					if (copied) {
						zval_ptr_dtor(copied);
						efree(copied);
					}
					if (is_sso) {
						JLD(rc, newJArray, sso_idx);
					} else {
						JHSD(rc, newHsArray, kindex, klen);
					}
					break;
				}
			}

			JSLN(KValue, old_obj->key_index, kindex)
		}
		new_obj->key_index = newKeyIndex;
		new_obj->hs_array = newHsArray;
	}

	new_obj->array = newJArray;
	new_obj->counter = old_obj->counter;
	/* The approximate payload total is a pure function of the key set and the
	   value shape, and a clone reproduces both — so it carries over with the
	   population count rather than being re-derived by a second O(n) walk. */
	new_obj->approx_payload_bytes = old_obj->approx_payload_bytes;
	/* Do not trust a copied append watermark: force the next `$j[] =` to
	 * recompute the empty slot, otherwise a cloned array would append at
	 * index 0 and overwrite an existing element. */
	new_obj->next_empty_is_valid = 0;
	judy_init_type_flags(new_obj, old_obj->type);

	if (new_obj->is_string_keyed && !new_obj->key_scratch) {
		new_obj->key_scratch = emalloc(PHP_JUDY_MAX_LENGTH);
	}

	/* Clone builds a second copy of both stores by hand rather than going
	 * through the shared write path, so it is the other place they can drift.
	 * Compiles to nothing without --enable-judy-debug-mirror. */
	JUDY_ASSERT_MIRROR(old_obj, "clone (source)");
	JUDY_ASSERT_MIRROR(new_obj, "clone (result)");

	return &new_obj->std;
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

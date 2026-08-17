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

#ifndef PHP_JUDY_H
#define PHP_JUDY_H

#define PHP_JUDY_VERSION "2.5.0"
#define PHP_JUDY_EXTNAME "judy"

/* Windows x64 (LLP64): Force 64-bit Word_t to match libjudy ABI.
 *
 * MSVC x64 defines 'unsigned long' as 4 bytes (LLP64 model). Our CI
 * builds libjudy from source with Word_t = unsigned __int64 = 8 bytes.
 * This override ensures our extension uses the same 8-byte Word_t,
 * matching the library's ABI on x64 Windows.
 *
 * Defining _WORD_T before including Judy.h tells it to skip its own
 * Word_t typedef, using ours instead. */
#ifdef _WIN64
#define _WORD_T
typedef unsigned __int64 Word_t, * PWord_t;
#endif

/* Disable default Judy error handler which calls exit(1).
 * With JUDYERROR_NOTEST, Judy macros pass NULL for PJError_t,
 * avoiding JError_t stack allocations (whose size depends on Word_t)
 * and letting us handle errors via return value checks instead. */
#define JUDYERROR_NOTEST 1

#include <Judy.h>

/* Fix PJERR/PPJERR for Windows x64.
 *
 * Judy.h defines PJERR as ((Pvoid_t)(~0UL)). On MSVC x64, unsigned long
 * is 4 bytes (LLP64), so ~0UL = 0xFFFFFFFF, making PJERR a 32-bit sentinel
 * (0x00000000FFFFFFFF) instead of the correct all-ones pointer. This causes
 * error return comparisons to fail. Redefine with ~(size_t)0 to get the
 * correct 64-bit sentinel (0xFFFFFFFFFFFFFFFF). */
#ifdef _WIN64
#undef PJERR
#undef PPJERR
#define PJERR  ((Pvoid_t)(~(size_t)0))
#define PPJERR ((PPvoid_t)(~(size_t)0))
#endif

/* Safe JudyL/JudySL value access macros.
 *
 * JudyL and JudySL store values in Word_t-sized slots internally, but the
 * C API returns PPvoid_t (void**) pointers to these slots. These macros
 * always read/write exactly sizeof(Word_t) bytes, which is correct on all
 * platforms. On Unix LP64 and Windows x64 (with our Word_t override),
 * sizeof(Word_t) == sizeof(void*) == 8, so MIXED types (which store zval*
 * pointers in value slots) work correctly everywhere. */
#define JUDY_LVAL_READ(PV)       ((zend_long)(*(Word_t *)(PV)))
#define JUDY_LVAL_WRITE(PV, v)   (*(Word_t *)(PV) = (Word_t)(v))

#if defined(__GNUC__) || defined(__clang__)
#define JUDY_LIKELY(x)   __builtin_expect(!!(x), 1)
#define JUDY_UNLIKELY(x) __builtin_expect(!!(x), 0)
#else
#define JUDY_LIKELY(x)   (x)
#define JUDY_UNLIKELY(x) (x)
#endif

#define JUDY_MIXED_SUPPORTED 1
#define JUDY_MVAL_READ(PV)       ((zval *)(*(PV)))
#define JUDY_MVAL_WRITE(PV, p)   (*(PV) = (Pvoid_t)(p))

#define JUDY_PVAL_READ(PV)      ((judy_packed_value *)(*(PV)))
#define JUDY_PVAL_WRITE(PV, p)  (*(PV) = (Pvoid_t)(p))

#include "php.h"
#include "php_ini.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/info.h"

/* Packed value storage for TYPE_INT_TO_PACKED.
 * Tagged union: scalars stored directly (no serialize), complex types fall back. */
typedef enum _judy_packed_tag {
    JUDY_TAG_LONG       = 0,
    JUDY_TAG_DOUBLE     = 1,
    JUDY_TAG_TRUE       = 2,
    JUDY_TAG_FALSE      = 3,
    JUDY_TAG_NULL       = 4,
    JUDY_TAG_STRING     = 5,
    JUDY_TAG_SERIALIZED = 255
} judy_packed_tag;

typedef struct _judy_packed_value {
    uint8_t tag;
    union {
        zend_long lval;
        double    dval;
        struct { size_t len; char data[]; } str;
    } v;
} judy_packed_value;

static inline size_t judy_packed_value_size(judy_packed_value *p) {
    switch ((judy_packed_tag)p->tag) {
    case JUDY_TAG_LONG:       return offsetof(judy_packed_value, v) + sizeof(zend_long);
    case JUDY_TAG_DOUBLE:     return offsetof(judy_packed_value, v) + sizeof(double);
    case JUDY_TAG_TRUE:
    case JUDY_TAG_FALSE:
    case JUDY_TAG_NULL:       return offsetof(judy_packed_value, v);
    case JUDY_TAG_STRING:
    case JUDY_TAG_SERIALIZED: return offsetof(judy_packed_value, v) + sizeof(size_t) + p->v.str.len;
    default:
        zend_error(E_WARNING, "judy_packed_value: unknown tag %u", (unsigned)p->tag);
        return 0;
    }
}

extern zend_module_entry judy_module_entry;
#define phpext_judy_ptr &judy_module_entry

#ifdef PHP_WIN32
#    define PHP_JUDY_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#    define PHP_JUDY_API __attribute__ ((visibility("default")))
#else
#    define PHP_JUDY_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

extern const zend_function_entry judy_class_methods[];

/* {{{ judy_type
 internal Judy Array type (aka Judy1, JudyL and JudySL) */
typedef enum _judy_type {
    TYPE_BITSET=1,
    TYPE_INT_TO_INT,
    TYPE_INT_TO_MIXED,
    TYPE_STRING_TO_INT,
    TYPE_STRING_TO_MIXED,
    TYPE_INT_TO_PACKED,
    TYPE_STRING_TO_MIXED_HASH, /* JudyHS: O(1) avg hash lookup, parallel JudySL key index for iteration */
    TYPE_STRING_TO_INT_HASH,   /* JudyHS: O(1) avg hash lookup for string→int, parallel JudySL key index */
    TYPE_STRING_TO_MIXED_ADAPTIVE, /* SSO: JudyL for <8 bytes, JudyHS for longer + parallel JudySL */
    TYPE_STRING_TO_INT_ADAPTIVE    /* SSO for string→int */
} judy_type;
/* }}} */

#define JTYPE(jtype, type) { \
    if (type != TYPE_BITSET && type != TYPE_INT_TO_INT \
                           && type != TYPE_INT_TO_MIXED \
                           && type != TYPE_STRING_TO_INT \
                           && type != TYPE_STRING_TO_MIXED \
                           && type != TYPE_INT_TO_PACKED \
                           && type != TYPE_STRING_TO_MIXED_HASH \
                           && type != TYPE_STRING_TO_INT_HASH \
                           && type != TYPE_STRING_TO_MIXED_ADAPTIVE \
                           && type != TYPE_STRING_TO_INT_ADAPTIVE) { \
        php_error_docref(NULL, E_WARNING, "Not a valid Judy type. Please check the documentation for valid Judy type constant."); \
        jtype = 0; \
    } else { \
        jtype = type; \
    } \
}

#define JUDY_METHOD_ERROR_HANDLING \
    zend_error_handling error_handling; \
    zend_replace_error_handling(EH_THROW, NULL, &error_handling);

#define JUDY_METHOD_GET_OBJECT \
    zval *object = getThis(); \
    judy_object *intern = php_judy_object(Z_OBJ_P(object));

/* Performance optimization macros using cached type flags */
#define JUDY_IS_INTEGER_KEYED(intern) ((intern)->is_integer_keyed)
#define JUDY_IS_STRING_KEYED(intern) ((intern)->is_string_keyed)
#define JUDY_IS_MIXED_VALUE(intern) ((intern)->is_mixed_value)
#define JUDY_IS_PACKED_VALUE(intern) ((intern)->is_packed_value)
#define JUDY_IS_ADAPTIVE(intern) ((intern)->is_adaptive)

typedef struct _judy_object {
	Pvoid_t         array;               /* 8 — hottest field */
	Pvoid_t         key_index;           /* 8 */
	Pvoid_t         hs_array;            /* 8 — for longer strings in ADAPTIVE type */
	zend_long       counter;             /* 8 */
	zend_long       approx_payload_bytes;/* 8 — string-keyed types only; see judy_string_entry_bytes() */
	Word_t			next_empty;          /* 8 */
	zend_long       type;                /* 8 */
	/* Iterator state for Iterator interface methods */
	zval            iterator_key;        /* 16 */
	zval            iterator_data;       /* 16 */
	uint8_t         *key_scratch;        /* 8 — heap-allocated PHP_JUDY_MAX_LENGTH buffer */
	/* {{{ The flag block. Read this before adding a flag.

	   Zend MM serves small allocations from fixed size classes — ..., 112,
	   128, 160, 192, ... — so the cost of an object is not sizeof() but the
	   next class up from it. judy_object_new_ex() ecallocs
	   sizeof(judy_object) + zend_object_properties_size(ce), and that total
	   sits a few bytes below the 160-byte class with nothing to spare. One
	   more plain byte in this block does not cost one byte per instance: it
	   pushes the struct over the boundary into the 192-byte class and every
	   Judy object on the heap costs 32 bytes more.

	   That is not hypothetical. The block used to be eight zend_bools under
	   a comment reading "Pack all bools together (8 bytes)" — true when
	   written, and silently false the moment a ninth flag arrived.
	   mirror_payload (PR #100) was that ninth flag, and it moved
	   Judy::BITSET and Judy::INT_TO_INT from 160 B to 192 B per instance,
	   +20%, for one bit of state.

	   Hence bitfields. The seven below are read-mostly — six are pure caches
	   of ->type, set once in judy_init_type_flags(); mirror_payload is fixed
	   for the object's lifetime — so extracting a bit rather than loading a
	   byte costs nothing measurable on the read path. The two mutated during
	   iteration stay whole bytes: they are written often enough that a
	   read-modify-write on a shared word is not worth the space, and the
	   padding before ->std absorbs them for free.

	   Flag number ten belongs in the bitfield group, not next to it. If the
	   group ever fills, measure before spending a byte: the number that
	   matters is the emalloc'd heap delta CI compares, the *.heap.judy
	   entries of examples/benchmarks/judy-bench.php, which for BITSET and
	   INT_TO_INT is the object allocation and nothing else. sizeof() on
	   paper is not what gets allocated. */
	unsigned int    is_integer_keyed : 1;
	unsigned int    is_string_keyed  : 1;
	unsigned int    is_mixed_value   : 1;
	unsigned int    is_packed_value  : 1;
	unsigned int    is_hash_keyed    : 1;
	unsigned int    is_adaptive      : 1;
	/* Set iff optimizeIteration was requested AND this type can honour it.
	   Fixed for the object's lifetime — see judy_set_optimize_iteration(). */
	unsigned int    mirror_payload   : 1;
	/* Mutated during iteration; kept as bytes, see above. */
	zend_bool       next_empty_is_valid;
	zend_bool       iterator_initialized;
	/* }}} */
	zend_object     std;                 /* must be last */
} judy_object;

static inline judy_object *php_judy_object(zend_object *obj) {
	return (judy_object *)((char*)(obj) - offsetof(judy_object, std));
}

/* A key shorter than a Word_t fits inside the index itself, so ADAPTIVE stores
   it in a JudyL rather than the JudyHS. */
#define JUDY_SSO_MAX_LEN (sizeof(Word_t))

static inline int judy_pack_short_string_internal(const char *str, size_t len, Word_t *index)
{
	if (len >= JUDY_SSO_MAX_LEN) return 0;
	*index = 0;
	memcpy(index, str, len);
	return 1;
}

/* {{{ Payload mirroring — the optimizeIteration trade.

   The key_index of the four *_HASH / *_ADAPTIVE types is a JudySL whose value
   word is allocated and, by default, never written. Ordered traversal walks
   that index for the key and then does a *second*, independent lookup
   (JHSG/JLG) to fetch the value — 22 ns/element at 16-byte keys, 98 ns at
   40-byte (issue #85).

   With optimizeIteration on, STRING_TO_INT_HASH and the long-key half of
   STRING_TO_INT_ADAPTIVE mirror their Word_t payload into that spare word, so
   traversal reads the value from the cursor it already holds. That is a
   measured 24-38% off ordered traversal and 29-47% off values(), paid for with
   8-20% on overwrite and on increment(): every write now has to locate the
   key_index slot as well as the value slot.

   Which side of that trade is right depends on the workload of the individual
   array, so it is a per-instance constructor choice, not a global one, and it
   is fixed for the object's lifetime — flipping it on a populated array would
   mean rewriting every key_index slot. See judy_set_optimize_iteration().

   Three exclusions, all deliberate, all silent (the request is a performance
   hint; a type that cannot honour it reports so through
   Judy::isIterationOptimized() rather than throwing):

     - Every type without a key_index: BITSET, the INT_TO_* family,
       STRING_TO_INT and STRING_TO_MIXED. There is nothing to mirror into.
     - The _MIXED variants. Their payload is a zval*, so a mirror is a second
       pointer to a refcounted value and the rules that keeps are a separate
       piece of work (issue #85 step B5).
     - ADAPTIVE keys shorter than JUDY_SSO_MAX_LEN, even on an instance that
       asked for it. Their value lives in a JudyL keyed by the packed index and
       both sides reach it with a JLG — 17.6 ns against 184.7 ns for a JudySL
       descend of the same key (research/write-probe-cost). Nothing to win and
       an order of magnitude to lose.

   JUDY_MIRRORS_PAYLOAD() answers "does this (instance, key length) mirror?".
   Every read site that consults it must therefore have the key length in hand,
   which the ADAPTIVE traversal sites already compute to pick a store. The
   type test collapses to HASH-or-not because mirror_payload is only ever set
   on the two types judy_type_can_mirror() names. */
#define JUDY_MIRRORS_PAYLOAD(intern, klen) \
	((intern)->mirror_payload \
		&& ((intern)->type == TYPE_STRING_TO_INT_HASH \
			|| (size_t)(klen) >= JUDY_SSO_MAX_LEN))

/* The same question on the traversal branches that only ever see the two
   non-ADAPTIVE *_HASH types, where the key length is irrelevant and not always
   in hand. */
#define JUDY_MIRRORS_HASH_PAYLOAD(intern) \
	((intern)->mirror_payload && (intern)->type == TYPE_STRING_TO_INT_HASH)

/* The types that can honour optimizeIteration at all. */
static inline int judy_type_can_mirror(zend_long jtype)
{
	return jtype == TYPE_STRING_TO_INT_HASH || jtype == TYPE_STRING_TO_INT_ADAPTIVE;
}
/* }}} */

static inline void judy_init_type_flags(judy_object *intern, zend_long jtype)
{
	intern->type = jtype;
	/* Default off: an instance is exactly origin/main until something asks
	   otherwise. Every path that builds a Judy from another one has to
	   re-assert the flag afterwards — judy_set_optimize_iteration() is the
	   only way to turn it on, so a forgotten propagation degrades to the
	   safe, unmirrored behaviour rather than to a stale mirror. */
	intern->mirror_payload = 0;
	intern->is_integer_keyed = (jtype == TYPE_BITSET || jtype == TYPE_INT_TO_INT || jtype == TYPE_INT_TO_MIXED || jtype == TYPE_INT_TO_PACKED);
	intern->is_string_keyed = (jtype == TYPE_STRING_TO_INT || jtype == TYPE_STRING_TO_MIXED || jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_INT_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
	intern->is_mixed_value = (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED || jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE);
	intern->is_packed_value = (jtype == TYPE_INT_TO_PACKED);
	intern->is_hash_keyed = (jtype == TYPE_STRING_TO_MIXED_HASH || jtype == TYPE_STRING_TO_INT_HASH || jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
	intern->is_adaptive = (jtype == TYPE_STRING_TO_MIXED_ADAPTIVE || jtype == TYPE_STRING_TO_INT_ADAPTIVE);
}

/* {{{ Approximate payload accounting for the string-keyed types.

   libJudy exposes no accounting for JudySL/JudyHS — there is no JudySLMemUsed
   twin of JudyLMemUsed — so Judy::memoryUsage() has nothing exact to report for
   the six string-keyed types. Instead the extension keeps its own running total,
   maintained wherever intern->counter is, of the bytes it can attribute to each
   live entry:

     - the key bytes as stored, counted once per structure that holds a copy.
       The *_HASH types store every key twice (once in the JudyHS value store,
       once in the JudySL key_index); ADAPTIVE packs keys shorter than 8 bytes
       into the JudyL index word rather than copying them into JudyHS, so only
       its long keys are counted twice.
     - the Word_t value slot.
     - for the _MIXED variants, the sizeof(zval) box the extension emallocs to
       hold the value.

   What the total EXCLUDES, and why it is only ever an approximation: every byte
   libJudy allocates for its own trie branches, leaves and JudyHS hash
   structures; allocator rounding; and the PHP heap reachable from a stored zval
   (the string or array it points at, which memory_get_usage() does see). It is
   a LOWER BOUND on the real footprint — for JudyHS-backed types the node
   overhead is a large fraction of the total — and it must never be presented
   with the authority of the exact JudyLMemUsed figure the integer-keyed types
   return. It IS exact for what it claims: key bytes plus value slots plus zval
   boxes, and so it is a sound basis for tracking growth and comparing
   populations of the same type. */
static inline zend_long judy_string_entry_bytes(const judy_object *intern, Word_t klen)
{
	zend_long bytes;

	switch (intern->type) {
	case TYPE_STRING_TO_INT:
	case TYPE_STRING_TO_MIXED:
		/* Plain JudySL: one NUL-terminated key copy, value in the same trie. */
		bytes = (zend_long)klen + 1 + (zend_long)sizeof(Word_t);
		break;
	case TYPE_STRING_TO_INT_HASH:
	case TYPE_STRING_TO_MIXED_HASH:
		/* JudyHS key copy + JudySL key_index copy + value slot. */
		bytes = (zend_long)klen + (zend_long)klen + 1 + (zend_long)sizeof(Word_t);
		break;
	case TYPE_STRING_TO_INT_ADAPTIVE:
	case TYPE_STRING_TO_MIXED_ADAPTIVE:
		/* key_index copy + value slot; long keys are copied into JudyHS too,
		   short ones are packed into the JudyL index and cost nothing extra. */
		bytes = (zend_long)klen + 1 + (zend_long)sizeof(Word_t);
		if (klen >= 8) {
			bytes += (zend_long)klen;
		}
		break;
	default:
		return 0;
	}

	if (intern->is_mixed_value) {
		bytes += (zend_long)sizeof(zval);
	}
	return bytes;
}

static inline void judy_string_bytes_add(judy_object *intern, Word_t klen)
{
	intern->approx_payload_bytes += judy_string_entry_bytes(intern, klen);
}

static inline void judy_string_bytes_sub(judy_object *intern, Word_t klen)
{
	zend_long bytes = judy_string_entry_bytes(intern, klen);

	/* Clamp rather than go negative: a partially-failed clone or a rolled-back
	   insert must never make memoryUsage() report a nonsense value. */
	intern->approx_payload_bytes =
		(intern->approx_payload_bytes > bytes) ? intern->approx_payload_bytes - bytes : 0;
}
/* }}} */

/* Apply an optimizeIteration request to an object whose type is already set.
   Must be called after judy_init_type_flags(), which clears the flag. A request
   on a type that cannot honour it is dropped here, once, so no other code has
   to distinguish "not asked for" from "asked for and impossible". */
static inline void judy_set_optimize_iteration(judy_object *intern, zend_bool requested)
{
	intern->mirror_payload = (requested && judy_type_can_mirror(intern->type)) ? 1 : 0;
}

/* Max length, this must be a constant for it to work in
 * declarings as we cannot use runtime decided values at
 * compile time ofcourse
 *
 * TODO:	This needs to be handled better
 */
#define PHP_JUDY_MAX_LENGTH 65536

zend_object *judy_object_new(zend_class_entry *ce);
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr);

/* {{{ JUDY_ASSERT_MIRROR — internal consistency assertions.

   Compiled in only by `./configure --enable-judy-debug-mirror`, which defines
   JUDY_DEBUG_MIRROR. In a normal build the macro expands to nothing and
   judy_debug_check_mirror() is not compiled at all, so the shipped extension
   carries no cost and no extra symbol.

   The check is O(n) in the population, so it belongs only at call sites that
   are already O(n) — object teardown, clone — never per element. `where` names
   the call site and is printed on violation. On violation the process aborts;
   it never alters behaviour otherwise. */
#ifdef JUDY_DEBUG_MIRROR
void judy_debug_check_mirror(judy_object *intern, const char *where);
#define JUDY_ASSERT_MIRROR(intern, where) judy_debug_check_mirror((intern), (where))
#else
#define JUDY_ASSERT_MIRROR(intern, where) ((void)0)
#endif
/* }}} */

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv);
int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value);
int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty);
int judy_object_unset_dimension_helper(zval *object, zval *offset);

judy_packed_value *judy_pack_value(zval *value);
int judy_unpack_value(judy_packed_value *packed, zval *rv);

/* {{{ REGISTER_JUDY_CLASS_CONST_LONG */
#define REGISTER_JUDY_CLASS_CONST_LONG(const_name, value) \
    zend_declare_class_constant_long(judy_ce, const_name, sizeof(const_name)-1, (zend_long) value);
/* }}} */

/* Default number of elements shown by var_dump()/print_r()/debugger panels
 * (get_debug_info). Bounded so that dumping a multi-million-element Judy
 * neither hangs the IDE nor blows the DBGp transport. */
#define PHP_JUDY_DEFAULT_DEBUG_PREVIEW_SIZE 16

/* Local stringifier: ZEND_TOSTR is not available across every supported PHP
 * version, and the INI default has to be a string literal. */
#define PHP_JUDY_STR_(x) #x
#define PHP_JUDY_STR(x)  PHP_JUDY_STR_(x)

ZEND_BEGIN_MODULE_GLOBALS(judy)
    unsigned long    max_length;
    zend_long        debug_preview_size;
ZEND_END_MODULE_GLOBALS(judy)

ZEND_EXTERN_MODULE_GLOBALS(judy)

#ifdef ZTS
#define JUDY_G(v) TSRMG(judy_globals_id, zend_judy_globals *, v)
#else
#define JUDY_G(v) (judy_globals.v)
#endif

/* Grabbing CE's so that other exts can use the date objects too */
PHP_JUDY_API zend_class_entry *php_judy_ce(void);

#endif    /* PHP_JUDY_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

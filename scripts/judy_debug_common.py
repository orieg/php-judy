# php-judy debugger printers — the debugger-independent half.
#
# Everything php-judy-specific lives here: the judy_type table, the flag block,
# which storage root plays which role for each type, and the report layout. The
# two front-ends — scripts/judy_lldb.py and scripts/judy_gdb.py — only know how
# to read fields out of their own debugger's value objects; they hand the values
# to summary_line() / report_lines() below and print what comes back.
#
# The split exists so the two cannot drift. If you add a flag, a type or a
# storage root, change it HERE and both debuggers pick it up.
#
# See scripts/judy_lldb.py for the load instructions, the build flags the
# printers need, and why the Judy tree itself is not walked.
#
# Pure Python: no lldb, no gdb, no PHP. Importable on its own, which is what
# makes the tables testable.

# judy_type values, mirroring php_judy.h. Both front-ends prefer the enum in
# the debug info (so the names cannot drift from judy_type_name() in
# php_judy.c) and fall back to this only when the enum is not there.
TYPE_FALLBACK = {
    1: "BITSET",
    2: "INT_TO_INT",
    3: "INT_TO_MIXED",
    4: "STRING_TO_INT",
    5: "STRING_TO_MIXED",
    6: "INT_TO_PACKED",
    7: "STRING_TO_MIXED_HASH",
    8: "STRING_TO_INT_HASH",
    9: "STRING_TO_MIXED_ADAPTIVE",
    10: "STRING_TO_INT_ADAPTIVE",
}

# The six caches judy_init_type_flags() derives from ->type.
INTEGER_KEYED = {"BITSET", "INT_TO_INT", "INT_TO_MIXED", "INT_TO_PACKED"}
STRING_KEYED = {
    "STRING_TO_INT", "STRING_TO_MIXED", "STRING_TO_INT_HASH",
    "STRING_TO_MIXED_HASH", "STRING_TO_INT_ADAPTIVE", "STRING_TO_MIXED_ADAPTIVE",
}
MIXED_VALUE = {
    "INT_TO_MIXED", "STRING_TO_MIXED", "STRING_TO_MIXED_HASH",
    "STRING_TO_MIXED_ADAPTIVE",
}
PACKED_VALUE = {"INT_TO_PACKED"}
HASH_KEYED = {
    "STRING_TO_INT_HASH", "STRING_TO_MIXED_HASH",
    "STRING_TO_INT_ADAPTIVE", "STRING_TO_MIXED_ADAPTIVE",
}
ADAPTIVE = {"STRING_TO_INT_ADAPTIVE", "STRING_TO_MIXED_ADAPTIVE"}

# judy_type_can_mirror(): the only two types optimizeIteration can reach.
CAN_MIRROR = {"STRING_TO_INT_HASH", "STRING_TO_INT_ADAPTIVE"}

DERIVED_FLAGS = ("is_integer_keyed", "is_string_keyed", "is_mixed_value",
                 "is_packed_value", "is_hash_keyed", "is_adaptive")
ALL_FLAGS = DERIVED_FLAGS + ("mirror_payload", "next_empty_is_valid",
                             "iterator_initialized")

ROOT_FIELDS = ("array", "key_index", "hs_array")

# zval type tags, Zend/zend_types.h. Used by both front-ends' zval renderers.
ZVAL_TYPES = {
    0: "UNDEF", 1: "NULL", 2: "FALSE", 3: "TRUE", 4: "LONG", 5: "DOUBLE",
    6: "STRING", 7: "ARRAY", 8: "OBJECT", 9: "RESOURCE", 10: "REFERENCE",
}

# judy_packed_tag, php_judy.h.
PACKED_TAGS = {0: "LONG", 1: "DOUBLE", 2: "TRUE", 3: "FALSE", 4: "NULL",
               5: "STRING", 255: "SERIALIZED"}

NO_DEBUG_INFO_HINT = (
    "The target has no 'judy_object' type, so judy.so was almost certainly "
    "built with PHP's default CFLAGS (-O3 -flto), under which the compiler "
    "leaves behind a debug map pointing at a temporary object file that no "
    "longer exists and the debugger ends up with no types and no locals. "
    'Rebuild:\n    make clean && make EXTRA_CFLAGS="-g -O0 -fno-lto"')


def type_name(table, raw_type):
    if raw_type is None:
        return "UNKNOWN(?)"
    return table.get(raw_type, "UNKNOWN(%s)" % raw_type)


def expected_flags(tname):
    """What judy_init_type_flags() would have set for this type."""
    return {
        "is_integer_keyed": 1 if tname in INTEGER_KEYED else 0,
        "is_string_keyed": 1 if tname in STRING_KEYED else 0,
        "is_mixed_value": 1 if tname in MIXED_VALUE else 0,
        "is_packed_value": 1 if tname in PACKED_VALUE else 0,
        "is_hash_keyed": 1 if tname in HASH_KEYED else 0,
        "is_adaptive": 1 if tname in ADAPTIVE else 0,
    }


def store_roles(tname, mirrored):
    """[(field, role)] — what each Pvoid_t root holds for this type.

    Mirrors judy_string_value_slot()'s dispatch in php_judy.c and the per-type
    free path in judy_object_dtor()."""
    if tname == "BITSET":
        return [("array", "Judy1 — bitmap of set indexes, no value store"),
                ("key_index", "unused"),
                ("hs_array", "unused")]
    if tname in ("INT_TO_INT", "INT_TO_MIXED", "INT_TO_PACKED"):
        payload = {"INT_TO_INT": "zend_long in the slot",
                   "INT_TO_MIXED": "zval* in the slot",
                   "INT_TO_PACKED": "judy_packed_value* in the slot"}[tname]
        return [("array", "JudyL, int key -> " + payload),
                ("key_index", "unused"),
                ("hs_array", "unused")]
    if tname in ("STRING_TO_INT", "STRING_TO_MIXED"):
        payload = "zend_long" if tname == "STRING_TO_INT" else "zval*"
        return [("array", "JudySL — keys AND values in one trie, %s in the slot" % payload),
                ("key_index", "unused (the trie is already ordered)"),
                ("hs_array", "unused")]
    if tname in ("STRING_TO_INT_HASH", "STRING_TO_MIXED_HASH"):
        payload = "zend_long" if tname.endswith("INT_HASH") else "zval*"
        idx = "JudySL key index — sorted keys; payload slot %s" % (
            "MIRRORED (optimizeIteration on)" if mirrored
            else "allocated but unwritten, traversal re-looks-up the value")
        return [("array", "JudyHS VALUE STORE — key -> %s, O(1) point lookup, unordered" % payload),
                ("key_index", idx),
                ("hs_array", "unused")]
    if tname in ("STRING_TO_INT_ADAPTIVE", "STRING_TO_MIXED_ADAPTIVE"):
        payload = "zend_long" if tname.endswith("INT_ADAPTIVE") else "zval*"
        idx = "JudySL key index — sorted keys, ALL lengths; payload slot %s" % (
            "MIRRORED for keys >= 8 bytes (optimizeIteration on)" if mirrored
            else "allocated but unwritten")
        return [("array", "JudyL SHORT-KEY value store — keys < 8 bytes packed "
                          "into the index word -> %s" % payload),
                ("key_index", idx),
                ("hs_array", "JudyHS LONG-KEY value store — keys >= 8 bytes -> %s" % payload)]
    return [(f, "? (unrecognised type)") for f in ROOT_FIELDS]


SPLIT_NOTE = [
    "^ this is the key_index / value-store split: which keys exist and in what",
    "  ORDER is answered by the JudySL key_index; what the value IS comes from",
    "  the separate store. Nothing at the type level ties the two together —",
    "  see judy_debug_check_mirror() and the --enable-judy-debug-mirror build",
    "  in CONTRIBUTING.md.",
]


def quote_bytes(raw, truncated=False):
    """Binary-safe rendering. Judy string keys may hold any byte."""
    out = []
    for b in raw:
        c = b if isinstance(b, int) else ord(b)
        if 0x20 <= c < 0x7F and c not in (0x22, 0x5C):
            out.append(chr(c))
        else:
            out.append("\\x%02x" % c)
    return '"' + "".join(out) + '"' + ("..." if truncated else "")


def ptr(value):
    return "NULL" if not value else "0x%x" % value


def summary_line(f):
    """One-liner for `frame variable` / `print`."""
    tname = type_name(f["type_table"], f["raw_type"])
    on = [n for n in ALL_FLAGS if f["flags"].get(n)]
    return "Judy %s count=%s [%s]" % (
        tname,
        "?" if f["counter"] is None else f["counter"],
        " ".join(n[3:] if n.startswith("is_") else n for n in on) or "-",
    )


def report_lines(f):
    """The full `judy` breakdown, as a list of lines.

    `f` is a plain dict the front-end filled in by reading memory:
      addr, note, raw_type, type_table, type_source, counter,
      approx_payload, flags{}, roots{}, iterator_key, iterator_data,
      next_empty, key_scratch, key_scratch_text
    Any value may be None; the report says so rather than guessing."""
    tname = type_name(f["type_table"], f["raw_type"])
    flags = f["flags"]
    mirrored = bool(flags.get("mirror_payload"))
    out = []
    add = out.append

    add("judy_object @ %s" % ptr(f.get("addr")))
    if f.get("note"):
        add("  note              %s" % f["note"])
    add("  type              %s = %s   [names from %s]"
        % (f["raw_type"], tname, f["type_source"]))
    add("  counter           %s element(s)" % f["counter"])

    if tname in STRING_KEYED:
        add("  approx_payload    %s bytes  (string-keyed lower bound: key bytes "
            "+ value slots + zval boxes, excludes libJudy nodes)" % f["approx_payload"])
    else:
        add("  approx_payload    %s  (unused — integer-keyed types report exact "
            "JudyLMemUsed/Judy1MemUsed instead)" % f["approx_payload"])

    add("  flags (packed bitfield)")
    expected = expected_flags(tname)
    disagree = []
    for name in DERIVED_FLAGS:
        got = flags.get(name)
        mark = ""
        if got is None:
            mark = "   <not in debug info>"
        elif got != expected[name]:
            mark = "   <<< MISMATCH: type %s implies %d" % (tname, expected[name])
            disagree.append(name)
        add("    %-22s %s%s" % (name, "?" if got is None else got, mark))

    mp = flags.get("mirror_payload")
    if mp is None:
        mp_note = "<not in debug info>"
    elif mp and tname not in CAN_MIRROR:
        mp_note = "<<< MISMATCH: %s cannot mirror (judy_type_can_mirror)" % tname
        disagree.append("mirror_payload")
    elif mp:
        mp_note = ("optimizeIteration ON — key_index slots carry the payload"
                   + (" for keys >= 8 bytes" if tname in ADAPTIVE else ""))
    else:
        mp_note = ("off" if tname in CAN_MIRROR
                   else "off (this type can never honour optimizeIteration)")
    add("    %-22s %s   %s" % ("mirror_payload", "?" if mp is None else mp, mp_note))

    for name, hint in (("next_empty_is_valid", "guards the cached next_empty below"),
                       ("iterator_initialized", "a manual rewind()/next() cursor is live")):
        val = flags.get(name)
        add("    %-22s %s   (kept a whole byte, mutated during iteration; %s)"
            % (name, "?" if val is None else val, hint))

    if disagree:
        add("  !! the flag cache disagrees with ->type: %s" % ", ".join(disagree))
        add("     judy_init_type_flags() derives all six from ->type, so a mismatch")
        add("     means the object was built without it, or memory is corrupt.")

    add("  storage roots (Pvoid_t; contents opaque — the tree is NOT walked, see")
    add("  the header note in scripts/judy_lldb.py)")
    for field, role in store_roles(tname, mirrored):
        add("    %-10s %-16s %s" % (field, ptr(f["roots"].get(field)), role))
    if tname in HASH_KEYED:
        for line in SPLIT_NOTE:
            add("    " + line)

    add("  iterator / cursor state (Iterator methods; foreach uses judy_iterator)")
    add("    iterator_key     %s" % f["iterator_key"])
    add("    iterator_data    %s" % f["iterator_data"])
    add("    next_empty       %s   %s" % (
        "?" if f["next_empty"] is None else f["next_empty"],
        "cached, usable" if flags.get("next_empty_is_valid")
        else "STALE — a write invalidated it; recompute before trusting it"))
    ks = f.get("key_scratch")
    line = "    key_scratch      %s" % ptr(ks)
    if ks:
        if f.get("key_scratch_text"):
            line += "   -> %s" % f["key_scratch_text"]
        line += ("   (live cursor key)" if flags.get("iterator_initialized")
                 else "   (no walk in progress; contents are leftovers)")
    add(line)
    return out


HELP = """judy [<expression>] — decode a php-judy judy_object.

With no argument, looks for `intern`, then `object`, then `obj` in the current
frame. The argument may be a judy_object, a judy_object*, a zend_object* (which
gets rebased through offsetof(judy_object, std), the way php_judy_object()
does), or a zval holding a Judy instance. Any variable path works, e.g.
`judy it->intern.data`.

Reads memory only — nothing runs in the inferior — so this is safe on a core
dump and at a breakpoint in a crash handler. It does NOT walk the Judy tree;
see the header note in scripts/judy_lldb.py for why."""

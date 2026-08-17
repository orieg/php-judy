# php-judy gdb pretty-printers — the Linux/valgrind twin of scripts/judy_lldb.py.
#
# Same audience, same output, same deliberate limits: this decodes the raw
# `judy_object` struct for someone debugging the EXTENSION, not the PHP-visible
# view that the `get_debug_info` handler produces for var_dump()/Xdebug/IDEs.
#
# Read the header of scripts/judy_lldb.py first — it is the primary document
# and covers what is rendered, and why the Judy tree itself is not walked.
# Everything php-judy-specific is shared between the two front-ends in
# scripts/judy_debug_common.py, so they cannot drift.
#
#   LOADING
#
#       (gdb) source scripts/judy_gdb.py
#
#   or permanently, in ~/.gdbinit (absolute path required):
#
#       source /path/to/php-judy/scripts/judy_gdb.py
#
#   BUILD REQUIREMENT — as on macOS, the default build is not debuggable:
#   `./configure && make` inherits PHP's own CFLAGS (typically -O2/-O3
#   -DNDEBUG, often -flto), which at best inlines the locals away and at worst
#   leaves gdb with no usable types. Rebuild with:
#
#       make clean && make EXTRA_CFLAGS="-g -O0 -fno-lto"
#
#   USE WITH VALGRIND (the reason this file exists — see the valgrind recipe in
#   AGENTS.md and CONTRIBUTING.md):
#
#       valgrind --vgdb=yes --vgdb-error=0 php -n -d extension=modules/judy.so t.php
#       gdb -ex 'source scripts/judy_gdb.py' -ex 'target remote | vgdb' $(which php)
#
#   Then `judy intern` at the stop. Everything here is a plain memory read, so
#   it works over the vgdb remote and on a core dump (`gdb php core`).
#
#   WHAT YOU GET
#
#       judy_object / judy_iterator / judy_packed_value    one-line summaries
#       (gdb) judy [<expression>]                          full breakdown
#
#   `judy` defaults to `intern` in the current frame and accepts a judy_object,
#   a judy_object*, a zend_object* (rebased through offsetof(judy_object, std)
#   like php_judy_object() does), or a zval holding a Judy instance.
#
#   ONE GDB GOTCHA, not the printers' fault: a breakpoint set by function name
#   stops after the prologue but a local may still be unassigned on the first
#   source line, so `intern` there can be garbage. Break a line or two later
#   (`break php_judy.c:<line>`) or `next` once first.

import os
import sys

import gdb

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import judy_debug_common as jc  # noqa: E402


# ---------------------------------------------------------------------------
# judy_type -> name, from the debug info where possible.
# ---------------------------------------------------------------------------

_type_table_cache = {}


def _type_table():
    key = gdb.current_progspace().filename or "<no-exe>"
    cached = _type_table_cache.get(key)
    if cached is not None:
        return cached

    table, source = None, None
    for name in ("judy_type", "enum _judy_type"):
        try:
            t = gdb.lookup_type(name)
        except gdb.error:
            continue
        entries = {}
        for f in t.fields():
            fname = f.name or ""
            if fname.startswith("TYPE_"):
                fname = fname[len("TYPE_"):]
            entries[int(f.enumval)] = fname
        if entries:
            table, source = entries, "debug info (enum judy_type)"
            break

    if table is None:
        table, source = dict(jc.TYPE_FALLBACK), "built-in fallback table"

    _type_table_cache[key] = (table, source)
    return table, source


# ---------------------------------------------------------------------------
# Value helpers. Pure memory reads; nothing runs in the inferior.
# ---------------------------------------------------------------------------

def _member(v, *path):
    try:
        for name in path:
            if v is None:
                return None
            v = v[name]
        return v
    except (gdb.error, KeyError, RuntimeError):
        return None


def _u(v, *path):
    m = _member(v, *path)
    if m is None:
        return None
    try:
        return int(m) & 0xFFFFFFFFFFFFFFFF
    except (gdb.error, ValueError):
        return None


def _s(v, *path):
    m = _member(v, *path)
    if m is None:
        return None
    try:
        return int(m)
    except (gdb.error, ValueError):
        return None


def _addr_of(v):
    try:
        a = v.address
        return None if a is None else int(a)
    except (gdb.error, AttributeError):
        return None


def _read(addr, size):
    if not addr or size <= 0:
        return None
    try:
        return bytes(gdb.selected_inferior().read_memory(addr, size))
    except (gdb.MemoryError, gdb.error, OverflowError):
        return None


def _read_cstr(addr, limit=96):
    data = _read(addr, limit)
    if data is None:
        data = _read(addr, 16)
        if data is None:
            return None
    nul = data.find(b"\x00")
    truncated = nul < 0
    if not truncated:
        data = data[:nul]
    return jc.quote_bytes(data, truncated)


def _zval_str(z):
    if z is None:
        return "<unreadable>"
    tag = _u(z, "u1", "v", "type")
    if tag is None:
        return "<no zval type info>"
    if tag == 4:
        return "LONG %d" % (_s(z, "value", "lval") or 0)
    if tag == 5:
        d = _member(z, "value", "dval")
        return "DOUBLE %s" % ("?" if d is None else float(d))
    if tag == 6:
        sp = _member(z, "value", "str")
        if sp is None or int(sp) == 0:
            return "STRING <null zend_string*>"
        zs = sp.dereference()
        ln = _u(zs, "len")
        val = _member(zs, "val")
        if ln is None or val is None:
            return "STRING @0x%x <no zend_string type info>" % int(sp)
        show = min(ln, 96)
        raw = _read(_addr_of(val), show) if show else b""
        if raw is None:
            return "STRING(len=%d) <unreadable>" % ln
        return "STRING(len=%d) %s" % (ln, jc.quote_bytes(raw, ln > show))
    return jc.ZVAL_TYPES.get(tag, "type#%d" % tag)


# ---------------------------------------------------------------------------
# Getting to a judy_object from whatever the user has in hand.
# ---------------------------------------------------------------------------

def _typename(v):
    try:
        return str(v.type.strip_typedefs()).replace("const ", "").strip()
    except gdb.error:
        return ""


def _as_judy_object(v):
    """Returns (gdb.Value of judy_object, note) or (None, reason)."""
    if v is None:
        return None, "not a valid value"

    t = _typename(v)
    if t in ("judy_object *", "struct _judy_object *"):
        if int(v) == 0:
            return None, "judy_object* is NULL"
        return v.dereference(), None
    if t in ("judy_object", "struct _judy_object"):
        return v, None
    if t in ("zend_object *", "struct _zend_object *"):
        if int(v) == 0:
            return None, "zend_object* is NULL"
        return _rebase_zend_object(int(v))
    if t in ("zend_object", "struct _zend_object"):
        return _rebase_zend_object(_addr_of(v))
    if t in ("zval", "struct _zval_struct", "zval *", "struct _zval_struct *"):
        z = v.dereference() if t.endswith("*") else v
        obj = _member(z, "value", "obj")
        if obj is not None and int(obj) != 0:
            return _rebase_zend_object(int(obj))
        return None, "zval does not hold an object"

    return None, "don't know how to read a judy_object out of type '%s'" % t


def _judy_object_type():
    for name in ("judy_object", "struct _judy_object"):
        try:
            return gdb.lookup_type(name)
        except gdb.error:
            continue
    return None


def _rebase_zend_object(addr):
    """php_judy_object(): back up from the embedded ->std to the container."""
    if not addr:
        return None, "zend_object address unavailable"
    jo = _judy_object_type()
    if jo is None:
        return None, "no 'judy_object' type in the debug info.\n" + jc.NO_DEBUG_INFO_HINT
    offset = None
    for f in jo.fields():
        if f.name == "std":
            offset = f.bitpos // 8
            break
    if offset is None:
        return None, "judy_object has no 'std' field?"
    base = addr - offset
    try:
        val = gdb.Value(base).cast(jo.pointer()).dereference()
    except gdb.error as exc:
        return None, "could not materialise judy_object at 0x%x: %s" % (base, exc)
    return val, ("rebased from zend_object 0x%x (offsetof(judy_object, std) = %d)"
                 % (addr, offset))


def _collect(jo, note=None):
    table, source = _type_table()
    ks = _u(jo, "key_scratch")
    return {
        "addr": _addr_of(jo),
        "note": note,
        "raw_type": _s(jo, "type"),
        "type_table": table,
        "type_source": source,
        "counter": _s(jo, "counter"),
        "approx_payload": _s(jo, "approx_payload_bytes"),
        "flags": dict((n, _u(jo, n)) for n in jc.ALL_FLAGS),
        "roots": dict((n, _u(jo, n)) for n in jc.ROOT_FIELDS),
        "iterator_key": _zval_str(_member(jo, "iterator_key")),
        "iterator_data": _zval_str(_member(jo, "iterator_data")),
        "next_empty": _u(jo, "next_empty"),
        "key_scratch": ks,
        "key_scratch_text": _read_cstr(ks) if ks else None,
    }


# ---------------------------------------------------------------------------
# Pretty printers.
# ---------------------------------------------------------------------------

def _deref(val):
    """(value, address-prefix). gdb replaces the whole `(T *) 0x...` line with
    the printer output, so a pointer's address has to come back in the string
    or it is lost."""
    try:
        if val.type.strip_typedefs().code == gdb.TYPE_CODE_PTR:
            if int(val) == 0:
                return None, ""
            return val.dereference(), "0x%x " % int(val)
    except gdb.error:
        return None, ""
    return val, ""


class _JudyObjectPrinter(object):
    def __init__(self, val):
        self.val = val

    def to_string(self):
        jo, err = _as_judy_object(self.val)
        if jo is None:
            return "<%s>" % err.splitlines()[0]
        addr = _addr_of(jo)
        prefix = ("0x%x " % addr) if addr else ""
        return prefix + jc.summary_line(_collect(jo))


class _JudyIteratorPrinter(object):
    def __init__(self, val):
        self.val = val

    def to_string(self):
        v, prefix = _deref(self.val)
        if v is None:
            return "judy_iterator NULL"
        valid = _u(v, "valid")
        return prefix + "judy_iterator valid=%s key=%s data=%s" % (
            "?" if valid is None else valid,
            _zval_str(_member(v, "key")), _zval_str(_member(v, "data")))


class _JudyPackedPrinter(object):
    def __init__(self, val):
        self.val = val

    def to_string(self):
        v, prefix = _deref(self.val)
        if v is None:
            return "judy_packed_value NULL"
        tag = _u(v, "tag")
        if tag is None:
            return prefix + "<unreadable judy_packed_value>"
        name = jc.PACKED_TAGS.get(tag, "UNKNOWN(%d)" % tag)
        if tag == 0:
            return prefix + "packed LONG %d" % (_s(v, "v", "lval") or 0)
        if tag == 1:
            d = _member(v, "v", "dval")
            return prefix + "packed DOUBLE %s" % ("?" if d is None else float(d))
        if tag in (5, 255):
            ln = _u(v, "v", "str", "len")
            blob = _member(v, "v", "str", "data")
            text = ""
            if blob is not None and ln:
                show = min(ln, 64)
                raw = _read(_addr_of(blob), show)
                if raw is not None:
                    text = " " + jc.quote_bytes(raw, ln > show)
            return prefix + "packed %s(len=%s)%s" % (name, ln, text)
        return prefix + "packed %s" % name


_PRINTERS = {
    "judy_object": _JudyObjectPrinter,
    "_judy_object": _JudyObjectPrinter,
    "judy_iterator": _JudyIteratorPrinter,
    "judy_packed_value": _JudyPackedPrinter,
    "_judy_packed_value": _JudyPackedPrinter,
}


def _names(t):
    """Every name a type answers to. judy_iterator is a typedef of an ANONYMOUS
    struct, so strip_typedefs() leaves it with neither name nor tag — the
    typedef name is the only handle on it, and looking only at the stripped
    type silently skips that printer."""
    out = set()
    for candidate in (t, t.strip_typedefs()):
        for name in (candidate.name, candidate.tag):
            if name:
                out.add(name)
    return out


def _lookup(val):
    try:
        t = val.type
    except gdb.error:
        return None
    if t.strip_typedefs().code == gdb.TYPE_CODE_PTR:
        t = t.strip_typedefs().target()
    if t.strip_typedefs().code not in (gdb.TYPE_CODE_STRUCT, gdb.TYPE_CODE_UNION):
        return None
    for name in _names(t):
        cls = _PRINTERS.get(name)
        if cls:
            return cls(val)
    return None


# ---------------------------------------------------------------------------
# `judy` command.
# ---------------------------------------------------------------------------

def _debug_info_hint():
    return "" if _judy_object_type() is not None else "\n\n" + jc.NO_DEBUG_INFO_HINT


class JudyCommand(gdb.Command):
    """Decode a php-judy judy_object. `judy --help` for details."""

    def __init__(self):
        super(JudyCommand, self).__init__("judy", gdb.COMMAND_DATA)

    def invoke(self, arg, from_tty):
        expr = (arg or "").strip()
        if expr in ("-h", "--help", "help"):
            gdb.write(jc.HELP + "\n")
            return

        note = None
        if expr:
            try:
                v = gdb.parse_and_eval(expr)
            except gdb.error as exc:
                raise gdb.GdbError("cannot resolve '%s': %s%s"
                                   % (expr, exc, _debug_info_hint()))
        else:
            v = None
            for name in ("intern", "object", "obj", "result"):
                try:
                    v = gdb.parse_and_eval(name)
                except gdb.error:
                    continue
                note = "(no argument given; using `%s` from this frame)" % name
                break
            if v is None:
                raise gdb.GdbError(
                    "no argument given and no `intern`/`object`/`obj` in this "
                    "frame — pass an expression, e.g. `judy intern`"
                    + _debug_info_hint())

        jo, err = _as_judy_object(v)
        if jo is None:
            raise gdb.GdbError(err)
        if err:
            note = ((note + " ") if note else "") + err

        gdb.write("\n".join(jc.report_lines(_collect(jo, note))) + "\n")


def _register():
    if _lookup not in gdb.pretty_printers:
        gdb.pretty_printers.append(_lookup)
    JudyCommand()
    gdb.write("php-judy: printers for judy_object / judy_iterator / "
              "judy_packed_value installed; `judy` command available "
              "(`judy --help`).\n")


_register()

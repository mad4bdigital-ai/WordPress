var $l = Object.defineProperty;
var El = (t, e, r) => e in t ? $l(t, e, { enumerable: !0, configurable: !0, writable: !0, value: r }) : t[e] = r;
var ve = (t, e, r) => El(t, typeof e != "symbol" ? e + "" : e, r);
function z(t, e, r) {
  function n(o, c) {
    var u;
    Object.defineProperty(o, "_zod", {
      value: o._zod ?? {},
      enumerable: !1
    }), (u = o._zod).traits ?? (u.traits = /* @__PURE__ */ new Set()), o._zod.traits.add(t), e(o, c);
    for (const l in a.prototype)
      l in o || Object.defineProperty(o, l, { value: a.prototype[l].bind(o) });
    o._zod.constr = a, o._zod.def = c;
  }
  const s = (r == null ? void 0 : r.Parent) ?? Object;
  class i extends s {
  }
  Object.defineProperty(i, "name", { value: t });
  function a(o) {
    var c;
    const u = r != null && r.Parent ? new i() : this;
    n(u, o), (c = u._zod).deferred ?? (c.deferred = []);
    for (const l of u._zod.deferred)
      l();
    return u;
  }
  return Object.defineProperty(a, "init", { value: n }), Object.defineProperty(a, Symbol.hasInstance, {
    value: (o) => {
      var c, u;
      return r != null && r.Parent && o instanceof r.Parent ? !0 : (u = (c = o == null ? void 0 : o._zod) == null ? void 0 : c.traits) == null ? void 0 : u.has(t);
    }
  }), Object.defineProperty(a, "name", { value: t }), a;
}
class Ir extends Error {
  constructor() {
    super("Encountered Promise during synchronous parse. Use .parseAsync() instead.");
  }
}
const jc = {};
function jt(t) {
  return jc;
}
function zc(t) {
  const e = Object.values(t).filter((n) => typeof n == "number");
  return Object.entries(t).filter(([n, s]) => e.indexOf(+n) === -1).map(([n, s]) => s);
}
function Tl(t, e) {
  return typeof e == "bigint" ? e.toString() : e;
}
function $i(t) {
  return {
    get value() {
      {
        const e = t();
        return Object.defineProperty(this, "value", { value: e }), e;
      }
    }
  };
}
function Ei(t) {
  return t == null;
}
function Ti(t) {
  const e = t.startsWith("^") ? 1 : 0, r = t.endsWith("$") ? t.length - 1 : t.length;
  return t.slice(e, r);
}
function Rl(t, e) {
  const r = (t.toString().split(".")[1] || "").length, n = (e.toString().split(".")[1] || "").length, s = r > n ? r : n, i = Number.parseInt(t.toFixed(s).replace(".", "")), a = Number.parseInt(e.toFixed(s).replace(".", ""));
  return i % a / 10 ** s;
}
function $e(t, e, r) {
  Object.defineProperty(t, e, {
    get() {
      {
        const n = r();
        return t[e] = n, n;
      }
    },
    set(n) {
      Object.defineProperty(t, e, {
        value: n
        // configurable: true,
      });
    },
    configurable: !0
  });
}
function xr(t, e, r) {
  Object.defineProperty(t, e, {
    value: r,
    writable: !0,
    enumerable: !0,
    configurable: !0
  });
}
function pr(t) {
  return JSON.stringify(t);
}
const qc = Error.captureStackTrace ? Error.captureStackTrace : (...t) => {
};
function Mn(t) {
  return typeof t == "object" && t !== null && !Array.isArray(t);
}
const Il = $i(() => {
  var t;
  if (typeof navigator < "u" && ((t = navigator == null ? void 0 : navigator.userAgent) != null && t.includes("Cloudflare")))
    return !1;
  try {
    const e = Function;
    return new e(""), !0;
  } catch {
    return !1;
  }
});
function Dn(t) {
  if (Mn(t) === !1)
    return !1;
  const e = t.constructor;
  if (e === void 0)
    return !0;
  const r = e.prototype;
  return !(Mn(r) === !1 || Object.prototype.hasOwnProperty.call(r, "isPrototypeOf") === !1);
}
const Pl = /* @__PURE__ */ new Set(["string", "number", "symbol"]);
function jr(t) {
  return t.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}
function Mt(t, e, r) {
  const n = new t._zod.constr(e ?? t._zod.def);
  return (!e || r != null && r.parent) && (n._zod.parent = t), n;
}
function Q(t) {
  const e = t;
  if (!e)
    return {};
  if (typeof e == "string")
    return { error: () => e };
  if ((e == null ? void 0 : e.message) !== void 0) {
    if ((e == null ? void 0 : e.error) !== void 0)
      throw new Error("Cannot specify both `message` and `error` params");
    e.error = e.message;
  }
  return delete e.message, typeof e.error == "string" ? { ...e, error: () => e.error } : e;
}
function Ol(t) {
  return Object.keys(t).filter((e) => t[e]._zod.optin === "optional" && t[e]._zod.optout === "optional");
}
const Cl = {
  safeint: [Number.MIN_SAFE_INTEGER, Number.MAX_SAFE_INTEGER],
  int32: [-2147483648, 2147483647],
  uint32: [0, 4294967295],
  float32: [-34028234663852886e22, 34028234663852886e22],
  float64: [-Number.MAX_VALUE, Number.MAX_VALUE]
};
function Al(t, e) {
  const r = {}, n = t._zod.def;
  for (const s in e) {
    if (!(s in n.shape))
      throw new Error(`Unrecognized key: "${s}"`);
    e[s] && (r[s] = n.shape[s]);
  }
  return Mt(t, {
    ...t._zod.def,
    shape: r,
    checks: []
  });
}
function Nl(t, e) {
  const r = { ...t._zod.def.shape }, n = t._zod.def;
  for (const s in e) {
    if (!(s in n.shape))
      throw new Error(`Unrecognized key: "${s}"`);
    e[s] && delete r[s];
  }
  return Mt(t, {
    ...t._zod.def,
    shape: r,
    checks: []
  });
}
function xl(t, e) {
  if (!Dn(e))
    throw new Error("Invalid input to extend: expected a plain object");
  const r = {
    ...t._zod.def,
    get shape() {
      const n = { ...t._zod.def.shape, ...e };
      return xr(this, "shape", n), n;
    },
    checks: []
    // delete existing checks
  };
  return Mt(t, r);
}
function jl(t, e) {
  return Mt(t, {
    ...t._zod.def,
    get shape() {
      const r = { ...t._zod.def.shape, ...e._zod.def.shape };
      return xr(this, "shape", r), r;
    },
    catchall: e._zod.def.catchall,
    checks: []
    // delete existing checks
  });
}
function zl(t, e, r) {
  const n = e._zod.def.shape, s = { ...n };
  if (r)
    for (const i in r) {
      if (!(i in n))
        throw new Error(`Unrecognized key: "${i}"`);
      r[i] && (s[i] = t ? new t({
        type: "optional",
        innerType: n[i]
      }) : n[i]);
    }
  else
    for (const i in n)
      s[i] = t ? new t({
        type: "optional",
        innerType: n[i]
      }) : n[i];
  return Mt(e, {
    ...e._zod.def,
    shape: s,
    checks: []
  });
}
function ql(t, e, r) {
  const n = e._zod.def.shape, s = { ...n };
  if (r)
    for (const i in r) {
      if (!(i in s))
        throw new Error(`Unrecognized key: "${i}"`);
      r[i] && (s[i] = new t({
        type: "nonoptional",
        innerType: n[i]
      }));
    }
  else
    for (const i in n)
      s[i] = new t({
        type: "nonoptional",
        innerType: n[i]
      });
  return Mt(e, {
    ...e._zod.def,
    shape: s,
    // optional: [],
    checks: []
  });
}
function Tr(t, e = 0) {
  var r;
  for (let n = e; n < t.issues.length; n++)
    if (((r = t.issues[n]) == null ? void 0 : r.continue) !== !0)
      return !0;
  return !1;
}
function Wt(t, e) {
  return e.map((r) => {
    var n;
    return (n = r).path ?? (n.path = []), r.path.unshift(t), r;
  });
}
function Hr(t) {
  return typeof t == "string" ? t : t == null ? void 0 : t.message;
}
function zt(t, e, r) {
  var s, i, a, o, c, u;
  const n = { ...t, path: t.path ?? [] };
  if (!t.message) {
    const l = Hr((a = (i = (s = t.inst) == null ? void 0 : s._zod.def) == null ? void 0 : i.error) == null ? void 0 : a.call(i, t)) ?? Hr((o = e == null ? void 0 : e.error) == null ? void 0 : o.call(e, t)) ?? Hr((c = r.customError) == null ? void 0 : c.call(r, t)) ?? Hr((u = r.localeError) == null ? void 0 : u.call(r, t)) ?? "Invalid input";
    n.message = l;
  }
  return delete n.inst, delete n.continue, e != null && e.reportInput || delete n.input, n;
}
function Ri(t) {
  return Array.isArray(t) ? "array" : typeof t == "string" ? "string" : "unknown";
}
function Pr(...t) {
  const [e, r, n] = t;
  return typeof e == "string" ? {
    message: e,
    code: "custom",
    input: r,
    inst: n
  } : { ...e };
}
const Mc = (t, e) => {
  t.name = "$ZodError", Object.defineProperty(t, "_zod", {
    value: t._zod,
    enumerable: !1
  }), Object.defineProperty(t, "issues", {
    value: e,
    enumerable: !1
  }), Object.defineProperty(t, "message", {
    get() {
      return JSON.stringify(e, Tl, 2);
    },
    enumerable: !0
    // configurable: false,
  }), Object.defineProperty(t, "toString", {
    value: () => t.message,
    enumerable: !1
  });
}, Dc = z("$ZodError", Mc), as = z("$ZodError", Mc, { Parent: Error });
function Ml(t, e = (r) => r.message) {
  const r = {}, n = [];
  for (const s of t.issues)
    s.path.length > 0 ? (r[s.path[0]] = r[s.path[0]] || [], r[s.path[0]].push(e(s))) : n.push(e(s));
  return { formErrors: n, fieldErrors: r };
}
function Dl(t, e) {
  const r = e || function(i) {
    return i.message;
  }, n = { _errors: [] }, s = (i) => {
    for (const a of i.issues)
      if (a.code === "invalid_union" && a.errors.length)
        a.errors.map((o) => s({ issues: o }));
      else if (a.code === "invalid_key")
        s({ issues: a.issues });
      else if (a.code === "invalid_element")
        s({ issues: a.issues });
      else if (a.path.length === 0)
        n._errors.push(r(a));
      else {
        let o = n, c = 0;
        for (; c < a.path.length; ) {
          const u = a.path[c];
          c === a.path.length - 1 ? (o[u] = o[u] || { _errors: [] }, o[u]._errors.push(r(a))) : o[u] = o[u] || { _errors: [] }, o = o[u], c++;
        }
      }
  };
  return s(t), n;
}
const Uc = (t) => (e, r, n, s) => {
  const i = n ? Object.assign(n, { async: !1 }) : { async: !1 }, a = e._zod.run({ value: r, issues: [] }, i);
  if (a instanceof Promise)
    throw new Ir();
  if (a.issues.length) {
    const o = new ((s == null ? void 0 : s.Err) ?? t)(a.issues.map((c) => zt(c, i, jt())));
    throw qc(o, s == null ? void 0 : s.callee), o;
  }
  return a.value;
}, Ul = /* @__PURE__ */ Uc(as), Lc = (t) => async (e, r, n, s) => {
  const i = n ? Object.assign(n, { async: !0 }) : { async: !0 };
  let a = e._zod.run({ value: r, issues: [] }, i);
  if (a instanceof Promise && (a = await a), a.issues.length) {
    const o = new ((s == null ? void 0 : s.Err) ?? t)(a.issues.map((c) => zt(c, i, jt())));
    throw qc(o, s == null ? void 0 : s.callee), o;
  }
  return a.value;
}, Ll = /* @__PURE__ */ Lc(as), Zc = (t) => (e, r, n) => {
  const s = n ? { ...n, async: !1 } : { async: !1 }, i = e._zod.run({ value: r, issues: [] }, s);
  if (i instanceof Promise)
    throw new Ir();
  return i.issues.length ? {
    success: !1,
    error: new (t ?? Dc)(i.issues.map((a) => zt(a, s, jt())))
  } : { success: !0, data: i.value };
}, Ii = /* @__PURE__ */ Zc(as), Fc = (t) => async (e, r, n) => {
  const s = n ? Object.assign(n, { async: !0 }) : { async: !0 };
  let i = e._zod.run({ value: r, issues: [] }, s);
  return i instanceof Promise && (i = await i), i.issues.length ? {
    success: !1,
    error: new t(i.issues.map((a) => zt(a, s, jt())))
  } : { success: !0, data: i.value };
}, Pi = /* @__PURE__ */ Fc(as), Zl = /^[cC][^\s-]{8,}$/, Fl = /^[0-9a-z]+$/, Hl = /^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/, Vl = /^[0-9a-vA-V]{20}$/, Kl = /^[A-Za-z0-9]{27}$/, Gl = /^[a-zA-Z0-9_-]{21}$/, Wl = /^P(?:(\d+W)|(?!.*W)(?=\d|T\d)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+([.,]\d+)?S)?)?)$/, Jl = /^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/, la = (t) => t ? new RegExp(`^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-${t}[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12})$`) : /^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000)$/, Bl = /^(?!\.)(?!.*\.\.)([A-Za-z0-9_'+\-\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\-]*\.)+[A-Za-z]{2,}$/, Ql = "^(\\p{Extended_Pictographic}|\\p{Emoji_Component})+$";
function Yl() {
  return new RegExp(Ql, "u");
}
const Xl = /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])$/, ed = /^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|::|([0-9a-fA-F]{1,4})?::([0-9a-fA-F]{1,4}:?){0,6})$/, td = /^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\/([0-9]|[1-2][0-9]|3[0-2])$/, rd = /^(([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}|::|([0-9a-fA-F]{1,4})?::([0-9a-fA-F]{1,4}:?){0,6})\/(12[0-8]|1[01][0-9]|[1-9]?[0-9])$/, nd = /^$|^(?:[0-9a-zA-Z+/]{4})*(?:(?:[0-9a-zA-Z+/]{2}==)|(?:[0-9a-zA-Z+/]{3}=))?$/, Hc = /^[A-Za-z0-9_-]*$/, sd = /^([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$/, id = /^\+(?:[0-9]){6,14}[0-9]$/, Vc = "(?:(?:\\d\\d[2468][048]|\\d\\d[13579][26]|\\d\\d0[48]|[02468][048]00|[13579][26]00)-02-29|\\d{4}-(?:(?:0[13578]|1[02])-(?:0[1-9]|[12]\\d|3[01])|(?:0[469]|11)-(?:0[1-9]|[12]\\d|30)|(?:02)-(?:0[1-9]|1\\d|2[0-8])))", ad = /* @__PURE__ */ new RegExp(`^${Vc}$`);
function Kc(t) {
  const e = "(?:[01]\\d|2[0-3]):[0-5]\\d";
  return typeof t.precision == "number" ? t.precision === -1 ? `${e}` : t.precision === 0 ? `${e}:[0-5]\\d` : `${e}:[0-5]\\d\\.\\d{${t.precision}}` : `${e}(?::[0-5]\\d(?:\\.\\d+)?)?`;
}
function od(t) {
  return new RegExp(`^${Kc(t)}$`);
}
function cd(t) {
  const e = Kc({ precision: t.precision }), r = ["Z"];
  t.local && r.push(""), t.offset && r.push("([+-]\\d{2}:\\d{2})");
  const n = `${e}(?:${r.join("|")})`;
  return new RegExp(`^${Vc}T(?:${n})$`);
}
const ud = (t) => {
  const e = t ? `[\\s\\S]{${(t == null ? void 0 : t.minimum) ?? 0},${(t == null ? void 0 : t.maximum) ?? ""}}` : "[\\s\\S]*";
  return new RegExp(`^${e}$`);
}, ld = /^\d+$/, dd = /^-?\d+(?:\.\d+)?/i, hd = /true|false/i, fd = /null/i, pd = /^[^A-Z]*$/, md = /^[^a-z]*$/, et = /* @__PURE__ */ z("$ZodCheck", (t, e) => {
  var r;
  t._zod ?? (t._zod = {}), t._zod.def = e, (r = t._zod).onattach ?? (r.onattach = []);
}), Gc = {
  number: "number",
  bigint: "bigint",
  object: "date"
}, Wc = /* @__PURE__ */ z("$ZodCheckLessThan", (t, e) => {
  et.init(t, e);
  const r = Gc[typeof e.value];
  t._zod.onattach.push((n) => {
    const s = n._zod.bag, i = (e.inclusive ? s.maximum : s.exclusiveMaximum) ?? Number.POSITIVE_INFINITY;
    e.value < i && (e.inclusive ? s.maximum = e.value : s.exclusiveMaximum = e.value);
  }), t._zod.check = (n) => {
    (e.inclusive ? n.value <= e.value : n.value < e.value) || n.issues.push({
      origin: r,
      code: "too_big",
      maximum: e.value,
      input: n.value,
      inclusive: e.inclusive,
      inst: t,
      continue: !e.abort
    });
  };
}), Jc = /* @__PURE__ */ z("$ZodCheckGreaterThan", (t, e) => {
  et.init(t, e);
  const r = Gc[typeof e.value];
  t._zod.onattach.push((n) => {
    const s = n._zod.bag, i = (e.inclusive ? s.minimum : s.exclusiveMinimum) ?? Number.NEGATIVE_INFINITY;
    e.value > i && (e.inclusive ? s.minimum = e.value : s.exclusiveMinimum = e.value);
  }), t._zod.check = (n) => {
    (e.inclusive ? n.value >= e.value : n.value > e.value) || n.issues.push({
      origin: r,
      code: "too_small",
      minimum: e.value,
      input: n.value,
      inclusive: e.inclusive,
      inst: t,
      continue: !e.abort
    });
  };
}), gd = /* @__PURE__ */ z("$ZodCheckMultipleOf", (t, e) => {
  et.init(t, e), t._zod.onattach.push((r) => {
    var n;
    (n = r._zod.bag).multipleOf ?? (n.multipleOf = e.value);
  }), t._zod.check = (r) => {
    if (typeof r.value != typeof e.value)
      throw new Error("Cannot mix number and bigint in multiple_of check.");
    (typeof r.value == "bigint" ? r.value % e.value === BigInt(0) : Rl(r.value, e.value) === 0) || r.issues.push({
      origin: typeof r.value,
      code: "not_multiple_of",
      divisor: e.value,
      input: r.value,
      inst: t,
      continue: !e.abort
    });
  };
}), _d = /* @__PURE__ */ z("$ZodCheckNumberFormat", (t, e) => {
  var a;
  et.init(t, e), e.format = e.format || "float64";
  const r = (a = e.format) == null ? void 0 : a.includes("int"), n = r ? "int" : "number", [s, i] = Cl[e.format];
  t._zod.onattach.push((o) => {
    const c = o._zod.bag;
    c.format = e.format, c.minimum = s, c.maximum = i, r && (c.pattern = ld);
  }), t._zod.check = (o) => {
    const c = o.value;
    if (r) {
      if (!Number.isInteger(c)) {
        o.issues.push({
          expected: n,
          format: e.format,
          code: "invalid_type",
          input: c,
          inst: t
        });
        return;
      }
      if (!Number.isSafeInteger(c)) {
        c > 0 ? o.issues.push({
          input: c,
          code: "too_big",
          maximum: Number.MAX_SAFE_INTEGER,
          note: "Integers must be within the safe integer range.",
          inst: t,
          origin: n,
          continue: !e.abort
        }) : o.issues.push({
          input: c,
          code: "too_small",
          minimum: Number.MIN_SAFE_INTEGER,
          note: "Integers must be within the safe integer range.",
          inst: t,
          origin: n,
          continue: !e.abort
        });
        return;
      }
    }
    c < s && o.issues.push({
      origin: "number",
      input: c,
      code: "too_small",
      minimum: s,
      inclusive: !0,
      inst: t,
      continue: !e.abort
    }), c > i && o.issues.push({
      origin: "number",
      input: c,
      code: "too_big",
      maximum: i,
      inst: t
    });
  };
}), yd = /* @__PURE__ */ z("$ZodCheckMaxLength", (t, e) => {
  var r;
  et.init(t, e), (r = t._zod.def).when ?? (r.when = (n) => {
    const s = n.value;
    return !Ei(s) && s.length !== void 0;
  }), t._zod.onattach.push((n) => {
    const s = n._zod.bag.maximum ?? Number.POSITIVE_INFINITY;
    e.maximum < s && (n._zod.bag.maximum = e.maximum);
  }), t._zod.check = (n) => {
    const s = n.value;
    if (s.length <= e.maximum)
      return;
    const a = Ri(s);
    n.issues.push({
      origin: a,
      code: "too_big",
      maximum: e.maximum,
      inclusive: !0,
      input: s,
      inst: t,
      continue: !e.abort
    });
  };
}), vd = /* @__PURE__ */ z("$ZodCheckMinLength", (t, e) => {
  var r;
  et.init(t, e), (r = t._zod.def).when ?? (r.when = (n) => {
    const s = n.value;
    return !Ei(s) && s.length !== void 0;
  }), t._zod.onattach.push((n) => {
    const s = n._zod.bag.minimum ?? Number.NEGATIVE_INFINITY;
    e.minimum > s && (n._zod.bag.minimum = e.minimum);
  }), t._zod.check = (n) => {
    const s = n.value;
    if (s.length >= e.minimum)
      return;
    const a = Ri(s);
    n.issues.push({
      origin: a,
      code: "too_small",
      minimum: e.minimum,
      inclusive: !0,
      input: s,
      inst: t,
      continue: !e.abort
    });
  };
}), wd = /* @__PURE__ */ z("$ZodCheckLengthEquals", (t, e) => {
  var r;
  et.init(t, e), (r = t._zod.def).when ?? (r.when = (n) => {
    const s = n.value;
    return !Ei(s) && s.length !== void 0;
  }), t._zod.onattach.push((n) => {
    const s = n._zod.bag;
    s.minimum = e.length, s.maximum = e.length, s.length = e.length;
  }), t._zod.check = (n) => {
    const s = n.value, i = s.length;
    if (i === e.length)
      return;
    const a = Ri(s), o = i > e.length;
    n.issues.push({
      origin: a,
      ...o ? { code: "too_big", maximum: e.length } : { code: "too_small", minimum: e.length },
      inclusive: !0,
      exact: !0,
      input: n.value,
      inst: t,
      continue: !e.abort
    });
  };
}), os = /* @__PURE__ */ z("$ZodCheckStringFormat", (t, e) => {
  var r, n;
  et.init(t, e), t._zod.onattach.push((s) => {
    const i = s._zod.bag;
    i.format = e.format, e.pattern && (i.patterns ?? (i.patterns = /* @__PURE__ */ new Set()), i.patterns.add(e.pattern));
  }), e.pattern ? (r = t._zod).check ?? (r.check = (s) => {
    e.pattern.lastIndex = 0, !e.pattern.test(s.value) && s.issues.push({
      origin: "string",
      code: "invalid_format",
      format: e.format,
      input: s.value,
      ...e.pattern ? { pattern: e.pattern.toString() } : {},
      inst: t,
      continue: !e.abort
    });
  }) : (n = t._zod).check ?? (n.check = () => {
  });
}), bd = /* @__PURE__ */ z("$ZodCheckRegex", (t, e) => {
  os.init(t, e), t._zod.check = (r) => {
    e.pattern.lastIndex = 0, !e.pattern.test(r.value) && r.issues.push({
      origin: "string",
      code: "invalid_format",
      format: "regex",
      input: r.value,
      pattern: e.pattern.toString(),
      inst: t,
      continue: !e.abort
    });
  };
}), Sd = /* @__PURE__ */ z("$ZodCheckLowerCase", (t, e) => {
  e.pattern ?? (e.pattern = pd), os.init(t, e);
}), kd = /* @__PURE__ */ z("$ZodCheckUpperCase", (t, e) => {
  e.pattern ?? (e.pattern = md), os.init(t, e);
}), $d = /* @__PURE__ */ z("$ZodCheckIncludes", (t, e) => {
  et.init(t, e);
  const r = jr(e.includes), n = new RegExp(typeof e.position == "number" ? `^.{${e.position}}${r}` : r);
  e.pattern = n, t._zod.onattach.push((s) => {
    const i = s._zod.bag;
    i.patterns ?? (i.patterns = /* @__PURE__ */ new Set()), i.patterns.add(n);
  }), t._zod.check = (s) => {
    s.value.includes(e.includes, e.position) || s.issues.push({
      origin: "string",
      code: "invalid_format",
      format: "includes",
      includes: e.includes,
      input: s.value,
      inst: t,
      continue: !e.abort
    });
  };
}), Ed = /* @__PURE__ */ z("$ZodCheckStartsWith", (t, e) => {
  et.init(t, e);
  const r = new RegExp(`^${jr(e.prefix)}.*`);
  e.pattern ?? (e.pattern = r), t._zod.onattach.push((n) => {
    const s = n._zod.bag;
    s.patterns ?? (s.patterns = /* @__PURE__ */ new Set()), s.patterns.add(r);
  }), t._zod.check = (n) => {
    n.value.startsWith(e.prefix) || n.issues.push({
      origin: "string",
      code: "invalid_format",
      format: "starts_with",
      prefix: e.prefix,
      input: n.value,
      inst: t,
      continue: !e.abort
    });
  };
}), Td = /* @__PURE__ */ z("$ZodCheckEndsWith", (t, e) => {
  et.init(t, e);
  const r = new RegExp(`.*${jr(e.suffix)}$`);
  e.pattern ?? (e.pattern = r), t._zod.onattach.push((n) => {
    const s = n._zod.bag;
    s.patterns ?? (s.patterns = /* @__PURE__ */ new Set()), s.patterns.add(r);
  }), t._zod.check = (n) => {
    n.value.endsWith(e.suffix) || n.issues.push({
      origin: "string",
      code: "invalid_format",
      format: "ends_with",
      suffix: e.suffix,
      input: n.value,
      inst: t,
      continue: !e.abort
    });
  };
}), Rd = /* @__PURE__ */ z("$ZodCheckOverwrite", (t, e) => {
  et.init(t, e), t._zod.check = (r) => {
    r.value = e.tx(r.value);
  };
});
class Id {
  constructor(e = []) {
    this.content = [], this.indent = 0, this && (this.args = e);
  }
  indented(e) {
    this.indent += 1, e(this), this.indent -= 1;
  }
  write(e) {
    if (typeof e == "function") {
      e(this, { execution: "sync" }), e(this, { execution: "async" });
      return;
    }
    const n = e.split(`
`).filter((a) => a), s = Math.min(...n.map((a) => a.length - a.trimStart().length)), i = n.map((a) => a.slice(s)).map((a) => " ".repeat(this.indent * 2) + a);
    for (const a of i)
      this.content.push(a);
  }
  compile() {
    const e = Function, r = this == null ? void 0 : this.args, s = [...((this == null ? void 0 : this.content) ?? [""]).map((i) => `  ${i}`)];
    return new e(...r, s.join(`
`));
  }
}
const Pd = {
  major: 4,
  minor: 0,
  patch: 0
}, Ee = /* @__PURE__ */ z("$ZodType", (t, e) => {
  var s;
  var r;
  t ?? (t = {}), t._zod.def = e, t._zod.bag = t._zod.bag || {}, t._zod.version = Pd;
  const n = [...t._zod.def.checks ?? []];
  t._zod.traits.has("$ZodCheck") && n.unshift(t);
  for (const i of n)
    for (const a of i._zod.onattach)
      a(t);
  if (n.length === 0)
    (r = t._zod).deferred ?? (r.deferred = []), (s = t._zod.deferred) == null || s.push(() => {
      t._zod.run = t._zod.parse;
    });
  else {
    const i = (a, o, c) => {
      let u = Tr(a), l;
      for (const p of o) {
        if (p._zod.def.when) {
          if (!p._zod.def.when(a))
            continue;
        } else if (u)
          continue;
        const v = a.issues.length, w = p._zod.check(a);
        if (w instanceof Promise && (c == null ? void 0 : c.async) === !1)
          throw new Ir();
        if (l || w instanceof Promise)
          l = (l ?? Promise.resolve()).then(async () => {
            await w, a.issues.length !== v && (u || (u = Tr(a, v)));
          });
        else {
          if (a.issues.length === v)
            continue;
          u || (u = Tr(a, v));
        }
      }
      return l ? l.then(() => a) : a;
    };
    t._zod.run = (a, o) => {
      const c = t._zod.parse(a, o);
      if (c instanceof Promise) {
        if (o.async === !1)
          throw new Ir();
        return c.then((u) => i(u, n, o));
      }
      return i(c, n, o);
    };
  }
  t["~standard"] = {
    validate: (i) => {
      var a;
      try {
        const o = Ii(t, i);
        return o.success ? { value: o.data } : { issues: (a = o.error) == null ? void 0 : a.issues };
      } catch {
        return Pi(t, i).then((c) => {
          var u;
          return c.success ? { value: c.data } : { issues: (u = c.error) == null ? void 0 : u.issues };
        });
      }
    },
    vendor: "zod",
    version: 1
  };
}), Oi = /* @__PURE__ */ z("$ZodString", (t, e) => {
  var r;
  Ee.init(t, e), t._zod.pattern = [...((r = t == null ? void 0 : t._zod.bag) == null ? void 0 : r.patterns) ?? []].pop() ?? ud(t._zod.bag), t._zod.parse = (n, s) => {
    if (e.coerce)
      try {
        n.value = String(n.value);
      } catch {
      }
    return typeof n.value == "string" || n.issues.push({
      expected: "string",
      code: "invalid_type",
      input: n.value,
      inst: t
    }), n;
  };
}), Re = /* @__PURE__ */ z("$ZodStringFormat", (t, e) => {
  os.init(t, e), Oi.init(t, e);
}), Od = /* @__PURE__ */ z("$ZodGUID", (t, e) => {
  e.pattern ?? (e.pattern = Jl), Re.init(t, e);
}), Cd = /* @__PURE__ */ z("$ZodUUID", (t, e) => {
  if (e.version) {
    const n = {
      v1: 1,
      v2: 2,
      v3: 3,
      v4: 4,
      v5: 5,
      v6: 6,
      v7: 7,
      v8: 8
    }[e.version];
    if (n === void 0)
      throw new Error(`Invalid UUID version: "${e.version}"`);
    e.pattern ?? (e.pattern = la(n));
  } else
    e.pattern ?? (e.pattern = la());
  Re.init(t, e);
}), Ad = /* @__PURE__ */ z("$ZodEmail", (t, e) => {
  e.pattern ?? (e.pattern = Bl), Re.init(t, e);
}), Nd = /* @__PURE__ */ z("$ZodURL", (t, e) => {
  Re.init(t, e), t._zod.check = (r) => {
    try {
      const n = r.value, s = new URL(n), i = s.href;
      e.hostname && (e.hostname.lastIndex = 0, e.hostname.test(s.hostname) || r.issues.push({
        code: "invalid_format",
        format: "url",
        note: "Invalid hostname",
        pattern: sd.source,
        input: r.value,
        inst: t,
        continue: !e.abort
      })), e.protocol && (e.protocol.lastIndex = 0, e.protocol.test(s.protocol.endsWith(":") ? s.protocol.slice(0, -1) : s.protocol) || r.issues.push({
        code: "invalid_format",
        format: "url",
        note: "Invalid protocol",
        pattern: e.protocol.source,
        input: r.value,
        inst: t,
        continue: !e.abort
      })), !n.endsWith("/") && i.endsWith("/") ? r.value = i.slice(0, -1) : r.value = i;
      return;
    } catch {
      r.issues.push({
        code: "invalid_format",
        format: "url",
        input: r.value,
        inst: t,
        continue: !e.abort
      });
    }
  };
}), xd = /* @__PURE__ */ z("$ZodEmoji", (t, e) => {
  e.pattern ?? (e.pattern = Yl()), Re.init(t, e);
}), jd = /* @__PURE__ */ z("$ZodNanoID", (t, e) => {
  e.pattern ?? (e.pattern = Gl), Re.init(t, e);
}), zd = /* @__PURE__ */ z("$ZodCUID", (t, e) => {
  e.pattern ?? (e.pattern = Zl), Re.init(t, e);
}), qd = /* @__PURE__ */ z("$ZodCUID2", (t, e) => {
  e.pattern ?? (e.pattern = Fl), Re.init(t, e);
}), Md = /* @__PURE__ */ z("$ZodULID", (t, e) => {
  e.pattern ?? (e.pattern = Hl), Re.init(t, e);
}), Dd = /* @__PURE__ */ z("$ZodXID", (t, e) => {
  e.pattern ?? (e.pattern = Vl), Re.init(t, e);
}), Ud = /* @__PURE__ */ z("$ZodKSUID", (t, e) => {
  e.pattern ?? (e.pattern = Kl), Re.init(t, e);
}), Ld = /* @__PURE__ */ z("$ZodISODateTime", (t, e) => {
  e.pattern ?? (e.pattern = cd(e)), Re.init(t, e);
}), Zd = /* @__PURE__ */ z("$ZodISODate", (t, e) => {
  e.pattern ?? (e.pattern = ad), Re.init(t, e);
}), Fd = /* @__PURE__ */ z("$ZodISOTime", (t, e) => {
  e.pattern ?? (e.pattern = od(e)), Re.init(t, e);
}), Hd = /* @__PURE__ */ z("$ZodISODuration", (t, e) => {
  e.pattern ?? (e.pattern = Wl), Re.init(t, e);
}), Vd = /* @__PURE__ */ z("$ZodIPv4", (t, e) => {
  e.pattern ?? (e.pattern = Xl), Re.init(t, e), t._zod.onattach.push((r) => {
    const n = r._zod.bag;
    n.format = "ipv4";
  });
}), Kd = /* @__PURE__ */ z("$ZodIPv6", (t, e) => {
  e.pattern ?? (e.pattern = ed), Re.init(t, e), t._zod.onattach.push((r) => {
    const n = r._zod.bag;
    n.format = "ipv6";
  }), t._zod.check = (r) => {
    try {
      new URL(`http://[${r.value}]`);
    } catch {
      r.issues.push({
        code: "invalid_format",
        format: "ipv6",
        input: r.value,
        inst: t,
        continue: !e.abort
      });
    }
  };
}), Gd = /* @__PURE__ */ z("$ZodCIDRv4", (t, e) => {
  e.pattern ?? (e.pattern = td), Re.init(t, e);
}), Wd = /* @__PURE__ */ z("$ZodCIDRv6", (t, e) => {
  e.pattern ?? (e.pattern = rd), Re.init(t, e), t._zod.check = (r) => {
    const [n, s] = r.value.split("/");
    try {
      if (!s)
        throw new Error();
      const i = Number(s);
      if (`${i}` !== s)
        throw new Error();
      if (i < 0 || i > 128)
        throw new Error();
      new URL(`http://[${n}]`);
    } catch {
      r.issues.push({
        code: "invalid_format",
        format: "cidrv6",
        input: r.value,
        inst: t,
        continue: !e.abort
      });
    }
  };
});
function Bc(t) {
  if (t === "")
    return !0;
  if (t.length % 4 !== 0)
    return !1;
  try {
    return atob(t), !0;
  } catch {
    return !1;
  }
}
const Jd = /* @__PURE__ */ z("$ZodBase64", (t, e) => {
  e.pattern ?? (e.pattern = nd), Re.init(t, e), t._zod.onattach.push((r) => {
    r._zod.bag.contentEncoding = "base64";
  }), t._zod.check = (r) => {
    Bc(r.value) || r.issues.push({
      code: "invalid_format",
      format: "base64",
      input: r.value,
      inst: t,
      continue: !e.abort
    });
  };
});
function Bd(t) {
  if (!Hc.test(t))
    return !1;
  const e = t.replace(/[-_]/g, (n) => n === "-" ? "+" : "/"), r = e.padEnd(Math.ceil(e.length / 4) * 4, "=");
  return Bc(r);
}
const Qd = /* @__PURE__ */ z("$ZodBase64URL", (t, e) => {
  e.pattern ?? (e.pattern = Hc), Re.init(t, e), t._zod.onattach.push((r) => {
    r._zod.bag.contentEncoding = "base64url";
  }), t._zod.check = (r) => {
    Bd(r.value) || r.issues.push({
      code: "invalid_format",
      format: "base64url",
      input: r.value,
      inst: t,
      continue: !e.abort
    });
  };
}), Yd = /* @__PURE__ */ z("$ZodE164", (t, e) => {
  e.pattern ?? (e.pattern = id), Re.init(t, e);
});
function Xd(t, e = null) {
  try {
    const r = t.split(".");
    if (r.length !== 3)
      return !1;
    const [n] = r;
    if (!n)
      return !1;
    const s = JSON.parse(atob(n));
    return !("typ" in s && (s == null ? void 0 : s.typ) !== "JWT" || !s.alg || e && (!("alg" in s) || s.alg !== e));
  } catch {
    return !1;
  }
}
const eh = /* @__PURE__ */ z("$ZodJWT", (t, e) => {
  Re.init(t, e), t._zod.check = (r) => {
    Xd(r.value, e.alg) || r.issues.push({
      code: "invalid_format",
      format: "jwt",
      input: r.value,
      inst: t,
      continue: !e.abort
    });
  };
}), Qc = /* @__PURE__ */ z("$ZodNumber", (t, e) => {
  Ee.init(t, e), t._zod.pattern = t._zod.bag.pattern ?? dd, t._zod.parse = (r, n) => {
    if (e.coerce)
      try {
        r.value = Number(r.value);
      } catch {
      }
    const s = r.value;
    if (typeof s == "number" && !Number.isNaN(s) && Number.isFinite(s))
      return r;
    const i = typeof s == "number" ? Number.isNaN(s) ? "NaN" : Number.isFinite(s) ? void 0 : "Infinity" : void 0;
    return r.issues.push({
      expected: "number",
      code: "invalid_type",
      input: s,
      inst: t,
      ...i ? { received: i } : {}
    }), r;
  };
}), th = /* @__PURE__ */ z("$ZodNumber", (t, e) => {
  _d.init(t, e), Qc.init(t, e);
}), rh = /* @__PURE__ */ z("$ZodBoolean", (t, e) => {
  Ee.init(t, e), t._zod.pattern = hd, t._zod.parse = (r, n) => {
    if (e.coerce)
      try {
        r.value = !!r.value;
      } catch {
      }
    const s = r.value;
    return typeof s == "boolean" || r.issues.push({
      expected: "boolean",
      code: "invalid_type",
      input: s,
      inst: t
    }), r;
  };
}), nh = /* @__PURE__ */ z("$ZodNull", (t, e) => {
  Ee.init(t, e), t._zod.pattern = fd, t._zod.values = /* @__PURE__ */ new Set([null]), t._zod.parse = (r, n) => {
    const s = r.value;
    return s === null || r.issues.push({
      expected: "null",
      code: "invalid_type",
      input: s,
      inst: t
    }), r;
  };
}), sh = /* @__PURE__ */ z("$ZodUnknown", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r) => r;
}), ih = /* @__PURE__ */ z("$ZodNever", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r, n) => (r.issues.push({
    expected: "never",
    code: "invalid_type",
    input: r.value,
    inst: t
  }), r);
});
function da(t, e, r) {
  t.issues.length && e.issues.push(...Wt(r, t.issues)), e.value[r] = t.value;
}
const ah = /* @__PURE__ */ z("$ZodArray", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r, n) => {
    const s = r.value;
    if (!Array.isArray(s))
      return r.issues.push({
        expected: "array",
        code: "invalid_type",
        input: s,
        inst: t
      }), r;
    r.value = Array(s.length);
    const i = [];
    for (let a = 0; a < s.length; a++) {
      const o = s[a], c = e.element._zod.run({
        value: o,
        issues: []
      }, n);
      c instanceof Promise ? i.push(c.then((u) => da(u, r, a))) : da(c, r, a);
    }
    return i.length ? Promise.all(i).then(() => r) : r;
  };
});
function Vr(t, e, r) {
  t.issues.length && e.issues.push(...Wt(r, t.issues)), e.value[r] = t.value;
}
function ha(t, e, r, n) {
  t.issues.length ? n[r] === void 0 ? r in n ? e.value[r] = void 0 : e.value[r] = t.value : e.issues.push(...Wt(r, t.issues)) : t.value === void 0 ? r in n && (e.value[r] = void 0) : e.value[r] = t.value;
}
const Yc = /* @__PURE__ */ z("$ZodObject", (t, e) => {
  Ee.init(t, e);
  const r = $i(() => {
    const p = Object.keys(e.shape);
    for (const w of p)
      if (!(e.shape[w] instanceof Ee))
        throw new Error(`Invalid element at key "${w}": expected a Zod schema`);
    const v = Ol(e.shape);
    return {
      shape: e.shape,
      keys: p,
      keySet: new Set(p),
      numKeys: p.length,
      optionalKeys: new Set(v)
    };
  });
  $e(t._zod, "propValues", () => {
    const p = e.shape, v = {};
    for (const w in p) {
      const b = p[w]._zod;
      if (b.values) {
        v[w] ?? (v[w] = /* @__PURE__ */ new Set());
        for (const E of b.values)
          v[w].add(E);
      }
    }
    return v;
  });
  const n = (p) => {
    const v = new Id(["shape", "payload", "ctx"]), w = r.value, b = (h) => {
      const g = pr(h);
      return `shape[${g}]._zod.run({ value: input[${g}], issues: [] }, ctx)`;
    };
    v.write("const input = payload.value;");
    const E = /* @__PURE__ */ Object.create(null);
    let _ = 0;
    for (const h of w.keys)
      E[h] = `key_${_++}`;
    v.write("const newResult = {}");
    for (const h of w.keys)
      if (w.optionalKeys.has(h)) {
        const g = E[h];
        v.write(`const ${g} = ${b(h)};`);
        const $ = pr(h);
        v.write(`
        if (${g}.issues.length) {
          if (input[${$}] === undefined) {
            if (${$} in input) {
              newResult[${$}] = undefined;
            }
          } else {
            payload.issues = payload.issues.concat(
              ${g}.issues.map((iss) => ({
                ...iss,
                path: iss.path ? [${$}, ...iss.path] : [${$}],
              }))
            );
          }
        } else if (${g}.value === undefined) {
          if (${$} in input) newResult[${$}] = undefined;
        } else {
          newResult[${$}] = ${g}.value;
        }
        `);
      } else {
        const g = E[h];
        v.write(`const ${g} = ${b(h)};`), v.write(`
          if (${g}.issues.length) payload.issues = payload.issues.concat(${g}.issues.map(iss => ({
            ...iss,
            path: iss.path ? [${pr(h)}, ...iss.path] : [${pr(h)}]
          })));`), v.write(`newResult[${pr(h)}] = ${g}.value`);
      }
    v.write("payload.value = newResult;"), v.write("return payload;");
    const m = v.compile();
    return (h, g) => m(p, h, g);
  };
  let s;
  const i = Mn, a = !jc.jitless, c = a && Il.value, u = e.catchall;
  let l;
  t._zod.parse = (p, v) => {
    l ?? (l = r.value);
    const w = p.value;
    if (!i(w))
      return p.issues.push({
        expected: "object",
        code: "invalid_type",
        input: w,
        inst: t
      }), p;
    const b = [];
    if (a && c && (v == null ? void 0 : v.async) === !1 && v.jitless !== !0)
      s || (s = n(e.shape)), p = s(p, v);
    else {
      p.value = {};
      const g = l.shape;
      for (const $ of l.keys) {
        const d = g[$], f = d._zod.run({ value: w[$], issues: [] }, v), y = d._zod.optin === "optional" && d._zod.optout === "optional";
        f instanceof Promise ? b.push(f.then((k) => y ? ha(k, p, $, w) : Vr(k, p, $))) : y ? ha(f, p, $, w) : Vr(f, p, $);
      }
    }
    if (!u)
      return b.length ? Promise.all(b).then(() => p) : p;
    const E = [], _ = l.keySet, m = u._zod, h = m.def.type;
    for (const g of Object.keys(w)) {
      if (_.has(g))
        continue;
      if (h === "never") {
        E.push(g);
        continue;
      }
      const $ = m.run({ value: w[g], issues: [] }, v);
      $ instanceof Promise ? b.push($.then((d) => Vr(d, p, g))) : Vr($, p, g);
    }
    return E.length && p.issues.push({
      code: "unrecognized_keys",
      keys: E,
      input: w,
      inst: t
    }), b.length ? Promise.all(b).then(() => p) : p;
  };
});
function fa(t, e, r, n) {
  for (const s of t)
    if (s.issues.length === 0)
      return e.value = s.value, e;
  return e.issues.push({
    code: "invalid_union",
    input: e.value,
    inst: r,
    errors: t.map((s) => s.issues.map((i) => zt(i, n, jt())))
  }), e;
}
const Xc = /* @__PURE__ */ z("$ZodUnion", (t, e) => {
  Ee.init(t, e), $e(t._zod, "optin", () => e.options.some((r) => r._zod.optin === "optional") ? "optional" : void 0), $e(t._zod, "optout", () => e.options.some((r) => r._zod.optout === "optional") ? "optional" : void 0), $e(t._zod, "values", () => {
    if (e.options.every((r) => r._zod.values))
      return new Set(e.options.flatMap((r) => Array.from(r._zod.values)));
  }), $e(t._zod, "pattern", () => {
    if (e.options.every((r) => r._zod.pattern)) {
      const r = e.options.map((n) => n._zod.pattern);
      return new RegExp(`^(${r.map((n) => Ti(n.source)).join("|")})$`);
    }
  }), t._zod.parse = (r, n) => {
    let s = !1;
    const i = [];
    for (const a of e.options) {
      const o = a._zod.run({
        value: r.value,
        issues: []
      }, n);
      if (o instanceof Promise)
        i.push(o), s = !0;
      else {
        if (o.issues.length === 0)
          return o;
        i.push(o);
      }
    }
    return s ? Promise.all(i).then((a) => fa(a, r, t, n)) : fa(i, r, t, n);
  };
}), oh = /* @__PURE__ */ z("$ZodDiscriminatedUnion", (t, e) => {
  Xc.init(t, e);
  const r = t._zod.parse;
  $e(t._zod, "propValues", () => {
    const s = {};
    for (const i of e.options) {
      const a = i._zod.propValues;
      if (!a || Object.keys(a).length === 0)
        throw new Error(`Invalid discriminated union option at index "${e.options.indexOf(i)}"`);
      for (const [o, c] of Object.entries(a)) {
        s[o] || (s[o] = /* @__PURE__ */ new Set());
        for (const u of c)
          s[o].add(u);
      }
    }
    return s;
  });
  const n = $i(() => {
    const s = e.options, i = /* @__PURE__ */ new Map();
    for (const a of s) {
      const o = a._zod.propValues[e.discriminator];
      if (!o || o.size === 0)
        throw new Error(`Invalid discriminated union option at index "${e.options.indexOf(a)}"`);
      for (const c of o) {
        if (i.has(c))
          throw new Error(`Duplicate discriminator value "${String(c)}"`);
        i.set(c, a);
      }
    }
    return i;
  });
  t._zod.parse = (s, i) => {
    const a = s.value;
    if (!Mn(a))
      return s.issues.push({
        code: "invalid_type",
        expected: "object",
        input: a,
        inst: t
      }), s;
    const o = n.value.get(a == null ? void 0 : a[e.discriminator]);
    return o ? o._zod.run(s, i) : e.unionFallback ? r(s, i) : (s.issues.push({
      code: "invalid_union",
      errors: [],
      note: "No matching discriminator",
      input: a,
      path: [e.discriminator],
      inst: t
    }), s);
  };
}), ch = /* @__PURE__ */ z("$ZodIntersection", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r, n) => {
    const s = r.value, i = e.left._zod.run({ value: s, issues: [] }, n), a = e.right._zod.run({ value: s, issues: [] }, n);
    return i instanceof Promise || a instanceof Promise ? Promise.all([i, a]).then(([c, u]) => pa(r, c, u)) : pa(r, i, a);
  };
});
function Ks(t, e) {
  if (t === e)
    return { valid: !0, data: t };
  if (t instanceof Date && e instanceof Date && +t == +e)
    return { valid: !0, data: t };
  if (Dn(t) && Dn(e)) {
    const r = Object.keys(e), n = Object.keys(t).filter((i) => r.indexOf(i) !== -1), s = { ...t, ...e };
    for (const i of n) {
      const a = Ks(t[i], e[i]);
      if (!a.valid)
        return {
          valid: !1,
          mergeErrorPath: [i, ...a.mergeErrorPath]
        };
      s[i] = a.data;
    }
    return { valid: !0, data: s };
  }
  if (Array.isArray(t) && Array.isArray(e)) {
    if (t.length !== e.length)
      return { valid: !1, mergeErrorPath: [] };
    const r = [];
    for (let n = 0; n < t.length; n++) {
      const s = t[n], i = e[n], a = Ks(s, i);
      if (!a.valid)
        return {
          valid: !1,
          mergeErrorPath: [n, ...a.mergeErrorPath]
        };
      r.push(a.data);
    }
    return { valid: !0, data: r };
  }
  return { valid: !1, mergeErrorPath: [] };
}
function pa(t, e, r) {
  if (e.issues.length && t.issues.push(...e.issues), r.issues.length && t.issues.push(...r.issues), Tr(t))
    return t;
  const n = Ks(e.value, r.value);
  if (!n.valid)
    throw new Error(`Unmergable intersection. Error path: ${JSON.stringify(n.mergeErrorPath)}`);
  return t.value = n.data, t;
}
const uh = /* @__PURE__ */ z("$ZodRecord", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r, n) => {
    const s = r.value;
    if (!Dn(s))
      return r.issues.push({
        expected: "record",
        code: "invalid_type",
        input: s,
        inst: t
      }), r;
    const i = [];
    if (e.keyType._zod.values) {
      const a = e.keyType._zod.values;
      r.value = {};
      for (const c of a)
        if (typeof c == "string" || typeof c == "number" || typeof c == "symbol") {
          const u = e.valueType._zod.run({ value: s[c], issues: [] }, n);
          u instanceof Promise ? i.push(u.then((l) => {
            l.issues.length && r.issues.push(...Wt(c, l.issues)), r.value[c] = l.value;
          })) : (u.issues.length && r.issues.push(...Wt(c, u.issues)), r.value[c] = u.value);
        }
      let o;
      for (const c in s)
        a.has(c) || (o = o ?? [], o.push(c));
      o && o.length > 0 && r.issues.push({
        code: "unrecognized_keys",
        input: s,
        inst: t,
        keys: o
      });
    } else {
      r.value = {};
      for (const a of Reflect.ownKeys(s)) {
        if (a === "__proto__")
          continue;
        const o = e.keyType._zod.run({ value: a, issues: [] }, n);
        if (o instanceof Promise)
          throw new Error("Async schemas not supported in object keys currently");
        if (o.issues.length) {
          r.issues.push({
            origin: "record",
            code: "invalid_key",
            issues: o.issues.map((u) => zt(u, n, jt())),
            input: a,
            path: [a],
            inst: t
          }), r.value[o.value] = o.value;
          continue;
        }
        const c = e.valueType._zod.run({ value: s[a], issues: [] }, n);
        c instanceof Promise ? i.push(c.then((u) => {
          u.issues.length && r.issues.push(...Wt(a, u.issues)), r.value[o.value] = u.value;
        })) : (c.issues.length && r.issues.push(...Wt(a, c.issues)), r.value[o.value] = c.value);
      }
    }
    return i.length ? Promise.all(i).then(() => r) : r;
  };
}), lh = /* @__PURE__ */ z("$ZodEnum", (t, e) => {
  Ee.init(t, e);
  const r = zc(e.entries);
  t._zod.values = new Set(r), t._zod.pattern = new RegExp(`^(${r.filter((n) => Pl.has(typeof n)).map((n) => typeof n == "string" ? jr(n) : n.toString()).join("|")})$`), t._zod.parse = (n, s) => {
    const i = n.value;
    return t._zod.values.has(i) || n.issues.push({
      code: "invalid_value",
      values: r,
      input: i,
      inst: t
    }), n;
  };
}), dh = /* @__PURE__ */ z("$ZodLiteral", (t, e) => {
  Ee.init(t, e), t._zod.values = new Set(e.values), t._zod.pattern = new RegExp(`^(${e.values.map((r) => typeof r == "string" ? jr(r) : r ? r.toString() : String(r)).join("|")})$`), t._zod.parse = (r, n) => {
    const s = r.value;
    return t._zod.values.has(s) || r.issues.push({
      code: "invalid_value",
      values: e.values,
      input: s,
      inst: t
    }), r;
  };
}), hh = /* @__PURE__ */ z("$ZodTransform", (t, e) => {
  Ee.init(t, e), t._zod.parse = (r, n) => {
    const s = e.transform(r.value, r);
    if (n.async)
      return (s instanceof Promise ? s : Promise.resolve(s)).then((a) => (r.value = a, r));
    if (s instanceof Promise)
      throw new Ir();
    return r.value = s, r;
  };
}), fh = /* @__PURE__ */ z("$ZodOptional", (t, e) => {
  Ee.init(t, e), t._zod.optin = "optional", t._zod.optout = "optional", $e(t._zod, "values", () => e.innerType._zod.values ? /* @__PURE__ */ new Set([...e.innerType._zod.values, void 0]) : void 0), $e(t._zod, "pattern", () => {
    const r = e.innerType._zod.pattern;
    return r ? new RegExp(`^(${Ti(r.source)})?$`) : void 0;
  }), t._zod.parse = (r, n) => e.innerType._zod.optin === "optional" ? e.innerType._zod.run(r, n) : r.value === void 0 ? r : e.innerType._zod.run(r, n);
}), ph = /* @__PURE__ */ z("$ZodNullable", (t, e) => {
  Ee.init(t, e), $e(t._zod, "optin", () => e.innerType._zod.optin), $e(t._zod, "optout", () => e.innerType._zod.optout), $e(t._zod, "pattern", () => {
    const r = e.innerType._zod.pattern;
    return r ? new RegExp(`^(${Ti(r.source)}|null)$`) : void 0;
  }), $e(t._zod, "values", () => e.innerType._zod.values ? /* @__PURE__ */ new Set([...e.innerType._zod.values, null]) : void 0), t._zod.parse = (r, n) => r.value === null ? r : e.innerType._zod.run(r, n);
}), mh = /* @__PURE__ */ z("$ZodDefault", (t, e) => {
  Ee.init(t, e), t._zod.optin = "optional", $e(t._zod, "values", () => e.innerType._zod.values), t._zod.parse = (r, n) => {
    if (r.value === void 0)
      return r.value = e.defaultValue, r;
    const s = e.innerType._zod.run(r, n);
    return s instanceof Promise ? s.then((i) => ma(i, e)) : ma(s, e);
  };
});
function ma(t, e) {
  return t.value === void 0 && (t.value = e.defaultValue), t;
}
const gh = /* @__PURE__ */ z("$ZodPrefault", (t, e) => {
  Ee.init(t, e), t._zod.optin = "optional", $e(t._zod, "values", () => e.innerType._zod.values), t._zod.parse = (r, n) => (r.value === void 0 && (r.value = e.defaultValue), e.innerType._zod.run(r, n));
}), _h = /* @__PURE__ */ z("$ZodNonOptional", (t, e) => {
  Ee.init(t, e), $e(t._zod, "values", () => {
    const r = e.innerType._zod.values;
    return r ? new Set([...r].filter((n) => n !== void 0)) : void 0;
  }), t._zod.parse = (r, n) => {
    const s = e.innerType._zod.run(r, n);
    return s instanceof Promise ? s.then((i) => ga(i, t)) : ga(s, t);
  };
});
function ga(t, e) {
  return !t.issues.length && t.value === void 0 && t.issues.push({
    code: "invalid_type",
    expected: "nonoptional",
    input: t.value,
    inst: e
  }), t;
}
const yh = /* @__PURE__ */ z("$ZodCatch", (t, e) => {
  Ee.init(t, e), t._zod.optin = "optional", $e(t._zod, "optout", () => e.innerType._zod.optout), $e(t._zod, "values", () => e.innerType._zod.values), t._zod.parse = (r, n) => {
    const s = e.innerType._zod.run(r, n);
    return s instanceof Promise ? s.then((i) => (r.value = i.value, i.issues.length && (r.value = e.catchValue({
      ...r,
      error: {
        issues: i.issues.map((a) => zt(a, n, jt()))
      },
      input: r.value
    }), r.issues = []), r)) : (r.value = s.value, s.issues.length && (r.value = e.catchValue({
      ...r,
      error: {
        issues: s.issues.map((i) => zt(i, n, jt()))
      },
      input: r.value
    }), r.issues = []), r);
  };
}), vh = /* @__PURE__ */ z("$ZodPipe", (t, e) => {
  Ee.init(t, e), $e(t._zod, "values", () => e.in._zod.values), $e(t._zod, "optin", () => e.in._zod.optin), $e(t._zod, "optout", () => e.out._zod.optout), t._zod.parse = (r, n) => {
    const s = e.in._zod.run(r, n);
    return s instanceof Promise ? s.then((i) => _a(i, e, n)) : _a(s, e, n);
  };
});
function _a(t, e, r) {
  return Tr(t) ? t : e.out._zod.run({ value: t.value, issues: t.issues }, r);
}
const wh = /* @__PURE__ */ z("$ZodReadonly", (t, e) => {
  Ee.init(t, e), $e(t._zod, "propValues", () => e.innerType._zod.propValues), $e(t._zod, "values", () => e.innerType._zod.values), $e(t._zod, "optin", () => e.innerType._zod.optin), $e(t._zod, "optout", () => e.innerType._zod.optout), t._zod.parse = (r, n) => {
    const s = e.innerType._zod.run(r, n);
    return s instanceof Promise ? s.then(ya) : ya(s);
  };
});
function ya(t) {
  return t.value = Object.freeze(t.value), t;
}
const bh = /* @__PURE__ */ z("$ZodCustom", (t, e) => {
  et.init(t, e), Ee.init(t, e), t._zod.parse = (r, n) => r, t._zod.check = (r) => {
    const n = r.value, s = e.fn(n);
    if (s instanceof Promise)
      return s.then((i) => va(i, r, n, t));
    va(s, r, n, t);
  };
});
function va(t, e, r, n) {
  if (!t) {
    const s = {
      code: "custom",
      input: r,
      inst: n,
      // incorporates params.error into issue reporting
      path: [...n._zod.def.path ?? []],
      // incorporates params.error into issue reporting
      continue: !n._zod.def.abort
      // params: inst._zod.def.params,
    };
    n._zod.def.params && (s.params = n._zod.def.params), e.issues.push(Pr(s));
  }
}
class eu {
  constructor() {
    this._map = /* @__PURE__ */ new Map(), this._idmap = /* @__PURE__ */ new Map();
  }
  add(e, ...r) {
    const n = r[0];
    if (this._map.set(e, n), n && typeof n == "object" && "id" in n) {
      if (this._idmap.has(n.id))
        throw new Error(`ID ${n.id} already exists in the registry`);
      this._idmap.set(n.id, e);
    }
    return this;
  }
  clear() {
    return this._map = /* @__PURE__ */ new Map(), this._idmap = /* @__PURE__ */ new Map(), this;
  }
  remove(e) {
    const r = this._map.get(e);
    return r && typeof r == "object" && "id" in r && this._idmap.delete(r.id), this._map.delete(e), this;
  }
  get(e) {
    const r = e._zod.parent;
    if (r) {
      const n = { ...this.get(r) ?? {} };
      return delete n.id, { ...n, ...this._map.get(e) };
    }
    return this._map.get(e);
  }
  has(e) {
    return this._map.has(e);
  }
}
function Sh() {
  return new eu();
}
const Sr = /* @__PURE__ */ Sh();
function kh(t, e) {
  return new t({
    type: "string",
    ...Q(e)
  });
}
function $h(t, e) {
  return new t({
    type: "string",
    format: "email",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function wa(t, e) {
  return new t({
    type: "string",
    format: "guid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Eh(t, e) {
  return new t({
    type: "string",
    format: "uuid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Th(t, e) {
  return new t({
    type: "string",
    format: "uuid",
    check: "string_format",
    abort: !1,
    version: "v4",
    ...Q(e)
  });
}
function Rh(t, e) {
  return new t({
    type: "string",
    format: "uuid",
    check: "string_format",
    abort: !1,
    version: "v6",
    ...Q(e)
  });
}
function Ih(t, e) {
  return new t({
    type: "string",
    format: "uuid",
    check: "string_format",
    abort: !1,
    version: "v7",
    ...Q(e)
  });
}
function Ph(t, e) {
  return new t({
    type: "string",
    format: "url",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Oh(t, e) {
  return new t({
    type: "string",
    format: "emoji",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Ch(t, e) {
  return new t({
    type: "string",
    format: "nanoid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Ah(t, e) {
  return new t({
    type: "string",
    format: "cuid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Nh(t, e) {
  return new t({
    type: "string",
    format: "cuid2",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function xh(t, e) {
  return new t({
    type: "string",
    format: "ulid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function jh(t, e) {
  return new t({
    type: "string",
    format: "xid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function zh(t, e) {
  return new t({
    type: "string",
    format: "ksuid",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function qh(t, e) {
  return new t({
    type: "string",
    format: "ipv4",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Mh(t, e) {
  return new t({
    type: "string",
    format: "ipv6",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Dh(t, e) {
  return new t({
    type: "string",
    format: "cidrv4",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Uh(t, e) {
  return new t({
    type: "string",
    format: "cidrv6",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Lh(t, e) {
  return new t({
    type: "string",
    format: "base64",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Zh(t, e) {
  return new t({
    type: "string",
    format: "base64url",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Fh(t, e) {
  return new t({
    type: "string",
    format: "e164",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Hh(t, e) {
  return new t({
    type: "string",
    format: "jwt",
    check: "string_format",
    abort: !1,
    ...Q(e)
  });
}
function Vh(t, e) {
  return new t({
    type: "string",
    format: "datetime",
    check: "string_format",
    offset: !1,
    local: !1,
    precision: null,
    ...Q(e)
  });
}
function Kh(t, e) {
  return new t({
    type: "string",
    format: "date",
    check: "string_format",
    ...Q(e)
  });
}
function Gh(t, e) {
  return new t({
    type: "string",
    format: "time",
    check: "string_format",
    precision: null,
    ...Q(e)
  });
}
function Wh(t, e) {
  return new t({
    type: "string",
    format: "duration",
    check: "string_format",
    ...Q(e)
  });
}
function Jh(t, e) {
  return new t({
    type: "number",
    checks: [],
    ...Q(e)
  });
}
function Bh(t, e) {
  return new t({
    type: "number",
    check: "number_format",
    abort: !1,
    format: "safeint",
    ...Q(e)
  });
}
function Qh(t, e) {
  return new t({
    type: "boolean",
    ...Q(e)
  });
}
function Yh(t, e) {
  return new t({
    type: "null",
    ...Q(e)
  });
}
function Xh(t) {
  return new t({
    type: "unknown"
  });
}
function ef(t, e) {
  return new t({
    type: "never",
    ...Q(e)
  });
}
function ba(t, e) {
  return new Wc({
    check: "less_than",
    ...Q(e),
    value: t,
    inclusive: !1
  });
}
function ws(t, e) {
  return new Wc({
    check: "less_than",
    ...Q(e),
    value: t,
    inclusive: !0
  });
}
function Sa(t, e) {
  return new Jc({
    check: "greater_than",
    ...Q(e),
    value: t,
    inclusive: !1
  });
}
function bs(t, e) {
  return new Jc({
    check: "greater_than",
    ...Q(e),
    value: t,
    inclusive: !0
  });
}
function ka(t, e) {
  return new gd({
    check: "multiple_of",
    ...Q(e),
    value: t
  });
}
function tu(t, e) {
  return new yd({
    check: "max_length",
    ...Q(e),
    maximum: t
  });
}
function Un(t, e) {
  return new vd({
    check: "min_length",
    ...Q(e),
    minimum: t
  });
}
function ru(t, e) {
  return new wd({
    check: "length_equals",
    ...Q(e),
    length: t
  });
}
function tf(t, e) {
  return new bd({
    check: "string_format",
    format: "regex",
    ...Q(e),
    pattern: t
  });
}
function rf(t) {
  return new Sd({
    check: "string_format",
    format: "lowercase",
    ...Q(t)
  });
}
function nf(t) {
  return new kd({
    check: "string_format",
    format: "uppercase",
    ...Q(t)
  });
}
function sf(t, e) {
  return new $d({
    check: "string_format",
    format: "includes",
    ...Q(e),
    includes: t
  });
}
function af(t, e) {
  return new Ed({
    check: "string_format",
    format: "starts_with",
    ...Q(e),
    prefix: t
  });
}
function of(t, e) {
  return new Td({
    check: "string_format",
    format: "ends_with",
    ...Q(e),
    suffix: t
  });
}
function zr(t) {
  return new Rd({
    check: "overwrite",
    tx: t
  });
}
function cf(t) {
  return zr((e) => e.normalize(t));
}
function uf() {
  return zr((t) => t.trim());
}
function lf() {
  return zr((t) => t.toLowerCase());
}
function df() {
  return zr((t) => t.toUpperCase());
}
function hf(t, e, r) {
  return new t({
    type: "array",
    element: e,
    // get element() {
    //   return element;
    // },
    ...Q(r)
  });
}
function ff(t, e, r) {
  const n = Q(r);
  return n.abort ?? (n.abort = !0), new t({
    type: "custom",
    check: "custom",
    fn: e,
    ...n
  });
}
function pf(t, e, r) {
  return new t({
    type: "custom",
    check: "custom",
    fn: e,
    ...Q(r)
  });
}
class $a {
  constructor(e) {
    this.counter = 0, this.metadataRegistry = (e == null ? void 0 : e.metadata) ?? Sr, this.target = (e == null ? void 0 : e.target) ?? "draft-2020-12", this.unrepresentable = (e == null ? void 0 : e.unrepresentable) ?? "throw", this.override = (e == null ? void 0 : e.override) ?? (() => {
    }), this.io = (e == null ? void 0 : e.io) ?? "output", this.seen = /* @__PURE__ */ new Map();
  }
  process(e, r = { path: [], schemaPath: [] }) {
    var p, v, w;
    var n;
    const s = e._zod.def, i = {
      guid: "uuid",
      url: "uri",
      datetime: "date-time",
      json_string: "json-string",
      regex: ""
      // do not set
    }, a = this.seen.get(e);
    if (a)
      return a.count++, r.schemaPath.includes(e) && (a.cycle = r.path), a.schema;
    const o = { schema: {}, count: 1, cycle: void 0, path: r.path };
    this.seen.set(e, o);
    const c = (v = (p = e._zod).toJSONSchema) == null ? void 0 : v.call(p);
    if (c)
      o.schema = c;
    else {
      const b = {
        ...r,
        schemaPath: [...r.schemaPath, e],
        path: r.path
      }, E = e._zod.parent;
      if (E)
        o.ref = E, this.process(E, b), this.seen.get(E).isParent = !0;
      else {
        const _ = o.schema;
        switch (s.type) {
          case "string": {
            const m = _;
            m.type = "string";
            const { minimum: h, maximum: g, format: $, patterns: d, contentEncoding: f } = e._zod.bag;
            if (typeof h == "number" && (m.minLength = h), typeof g == "number" && (m.maxLength = g), $ && (m.format = i[$] ?? $, m.format === "" && delete m.format), f && (m.contentEncoding = f), d && d.size > 0) {
              const y = [...d];
              y.length === 1 ? m.pattern = y[0].source : y.length > 1 && (o.schema.allOf = [
                ...y.map((k) => ({
                  ...this.target === "draft-7" ? { type: "string" } : {},
                  pattern: k.source
                }))
              ]);
            }
            break;
          }
          case "number": {
            const m = _, { minimum: h, maximum: g, format: $, multipleOf: d, exclusiveMaximum: f, exclusiveMinimum: y } = e._zod.bag;
            typeof $ == "string" && $.includes("int") ? m.type = "integer" : m.type = "number", typeof y == "number" && (m.exclusiveMinimum = y), typeof h == "number" && (m.minimum = h, typeof y == "number" && (y >= h ? delete m.minimum : delete m.exclusiveMinimum)), typeof f == "number" && (m.exclusiveMaximum = f), typeof g == "number" && (m.maximum = g, typeof f == "number" && (f <= g ? delete m.maximum : delete m.exclusiveMaximum)), typeof d == "number" && (m.multipleOf = d);
            break;
          }
          case "boolean": {
            const m = _;
            m.type = "boolean";
            break;
          }
          case "bigint": {
            if (this.unrepresentable === "throw")
              throw new Error("BigInt cannot be represented in JSON Schema");
            break;
          }
          case "symbol": {
            if (this.unrepresentable === "throw")
              throw new Error("Symbols cannot be represented in JSON Schema");
            break;
          }
          case "null": {
            _.type = "null";
            break;
          }
          case "any":
            break;
          case "unknown":
            break;
          case "undefined": {
            if (this.unrepresentable === "throw")
              throw new Error("Undefined cannot be represented in JSON Schema");
            break;
          }
          case "void": {
            if (this.unrepresentable === "throw")
              throw new Error("Void cannot be represented in JSON Schema");
            break;
          }
          case "never": {
            _.not = {};
            break;
          }
          case "date": {
            if (this.unrepresentable === "throw")
              throw new Error("Date cannot be represented in JSON Schema");
            break;
          }
          case "array": {
            const m = _, { minimum: h, maximum: g } = e._zod.bag;
            typeof h == "number" && (m.minItems = h), typeof g == "number" && (m.maxItems = g), m.type = "array", m.items = this.process(s.element, { ...b, path: [...b.path, "items"] });
            break;
          }
          case "object": {
            const m = _;
            m.type = "object", m.properties = {};
            const h = s.shape;
            for (const d in h)
              m.properties[d] = this.process(h[d], {
                ...b,
                path: [...b.path, "properties", d]
              });
            const g = new Set(Object.keys(h)), $ = new Set([...g].filter((d) => {
              const f = s.shape[d]._zod;
              return this.io === "input" ? f.optin === void 0 : f.optout === void 0;
            }));
            $.size > 0 && (m.required = Array.from($)), ((w = s.catchall) == null ? void 0 : w._zod.def.type) === "never" ? m.additionalProperties = !1 : s.catchall ? s.catchall && (m.additionalProperties = this.process(s.catchall, {
              ...b,
              path: [...b.path, "additionalProperties"]
            })) : this.io === "output" && (m.additionalProperties = !1);
            break;
          }
          case "union": {
            const m = _;
            m.anyOf = s.options.map((h, g) => this.process(h, {
              ...b,
              path: [...b.path, "anyOf", g]
            }));
            break;
          }
          case "intersection": {
            const m = _, h = this.process(s.left, {
              ...b,
              path: [...b.path, "allOf", 0]
            }), g = this.process(s.right, {
              ...b,
              path: [...b.path, "allOf", 1]
            }), $ = (f) => "allOf" in f && Object.keys(f).length === 1, d = [
              ...$(h) ? h.allOf : [h],
              ...$(g) ? g.allOf : [g]
            ];
            m.allOf = d;
            break;
          }
          case "tuple": {
            const m = _;
            m.type = "array";
            const h = s.items.map((d, f) => this.process(d, { ...b, path: [...b.path, "prefixItems", f] }));
            if (this.target === "draft-2020-12" ? m.prefixItems = h : m.items = h, s.rest) {
              const d = this.process(s.rest, {
                ...b,
                path: [...b.path, "items"]
              });
              this.target === "draft-2020-12" ? m.items = d : m.additionalItems = d;
            }
            s.rest && (m.items = this.process(s.rest, {
              ...b,
              path: [...b.path, "items"]
            }));
            const { minimum: g, maximum: $ } = e._zod.bag;
            typeof g == "number" && (m.minItems = g), typeof $ == "number" && (m.maxItems = $);
            break;
          }
          case "record": {
            const m = _;
            m.type = "object", m.propertyNames = this.process(s.keyType, { ...b, path: [...b.path, "propertyNames"] }), m.additionalProperties = this.process(s.valueType, {
              ...b,
              path: [...b.path, "additionalProperties"]
            });
            break;
          }
          case "map": {
            if (this.unrepresentable === "throw")
              throw new Error("Map cannot be represented in JSON Schema");
            break;
          }
          case "set": {
            if (this.unrepresentable === "throw")
              throw new Error("Set cannot be represented in JSON Schema");
            break;
          }
          case "enum": {
            const m = _, h = zc(s.entries);
            h.every((g) => typeof g == "number") && (m.type = "number"), h.every((g) => typeof g == "string") && (m.type = "string"), m.enum = h;
            break;
          }
          case "literal": {
            const m = _, h = [];
            for (const g of s.values)
              if (g === void 0) {
                if (this.unrepresentable === "throw")
                  throw new Error("Literal `undefined` cannot be represented in JSON Schema");
              } else if (typeof g == "bigint") {
                if (this.unrepresentable === "throw")
                  throw new Error("BigInt literals cannot be represented in JSON Schema");
                h.push(Number(g));
              } else
                h.push(g);
            if (h.length !== 0) if (h.length === 1) {
              const g = h[0];
              m.type = g === null ? "null" : typeof g, m.const = g;
            } else
              h.every((g) => typeof g == "number") && (m.type = "number"), h.every((g) => typeof g == "string") && (m.type = "string"), h.every((g) => typeof g == "boolean") && (m.type = "string"), h.every((g) => g === null) && (m.type = "null"), m.enum = h;
            break;
          }
          case "file": {
            const m = _, h = {
              type: "string",
              format: "binary",
              contentEncoding: "binary"
            }, { minimum: g, maximum: $, mime: d } = e._zod.bag;
            g !== void 0 && (h.minLength = g), $ !== void 0 && (h.maxLength = $), d ? d.length === 1 ? (h.contentMediaType = d[0], Object.assign(m, h)) : m.anyOf = d.map((f) => ({ ...h, contentMediaType: f })) : Object.assign(m, h);
            break;
          }
          case "transform": {
            if (this.unrepresentable === "throw")
              throw new Error("Transforms cannot be represented in JSON Schema");
            break;
          }
          case "nullable": {
            const m = this.process(s.innerType, b);
            _.anyOf = [m, { type: "null" }];
            break;
          }
          case "nonoptional": {
            this.process(s.innerType, b), o.ref = s.innerType;
            break;
          }
          case "success": {
            const m = _;
            m.type = "boolean";
            break;
          }
          case "default": {
            this.process(s.innerType, b), o.ref = s.innerType, _.default = JSON.parse(JSON.stringify(s.defaultValue));
            break;
          }
          case "prefault": {
            this.process(s.innerType, b), o.ref = s.innerType, this.io === "input" && (_._prefault = JSON.parse(JSON.stringify(s.defaultValue)));
            break;
          }
          case "catch": {
            this.process(s.innerType, b), o.ref = s.innerType;
            let m;
            try {
              m = s.catchValue(void 0);
            } catch {
              throw new Error("Dynamic catch values are not supported in JSON Schema");
            }
            _.default = m;
            break;
          }
          case "nan": {
            if (this.unrepresentable === "throw")
              throw new Error("NaN cannot be represented in JSON Schema");
            break;
          }
          case "template_literal": {
            const m = _, h = e._zod.pattern;
            if (!h)
              throw new Error("Pattern not found in template literal");
            m.type = "string", m.pattern = h.source;
            break;
          }
          case "pipe": {
            const m = this.io === "input" ? s.in._zod.def.type === "transform" ? s.out : s.in : s.out;
            this.process(m, b), o.ref = m;
            break;
          }
          case "readonly": {
            this.process(s.innerType, b), o.ref = s.innerType, _.readOnly = !0;
            break;
          }
          // passthrough types
          case "promise": {
            this.process(s.innerType, b), o.ref = s.innerType;
            break;
          }
          case "optional": {
            this.process(s.innerType, b), o.ref = s.innerType;
            break;
          }
          case "lazy": {
            const m = e._zod.innerType;
            this.process(m, b), o.ref = m;
            break;
          }
          case "custom": {
            if (this.unrepresentable === "throw")
              throw new Error("Custom types cannot be represented in JSON Schema");
            break;
          }
        }
      }
    }
    const u = this.metadataRegistry.get(e);
    return u && Object.assign(o.schema, u), this.io === "input" && Me(e) && (delete o.schema.examples, delete o.schema.default), this.io === "input" && o.schema._prefault && ((n = o.schema).default ?? (n.default = o.schema._prefault)), delete o.schema._prefault, this.seen.get(e).schema;
  }
  emit(e, r) {
    var l, p, v, w, b, E;
    const n = {
      cycles: (r == null ? void 0 : r.cycles) ?? "ref",
      reused: (r == null ? void 0 : r.reused) ?? "inline",
      // unrepresentable: _params?.unrepresentable ?? "throw",
      // uri: _params?.uri ?? ((id) => `${id}`),
      external: (r == null ? void 0 : r.external) ?? void 0
    }, s = this.seen.get(e);
    if (!s)
      throw new Error("Unprocessed schema. This is a bug in Zod.");
    const i = (_) => {
      var d;
      const m = this.target === "draft-2020-12" ? "$defs" : "definitions";
      if (n.external) {
        const f = (d = n.external.registry.get(_[0])) == null ? void 0 : d.id, y = n.external.uri ?? ((I) => I);
        if (f)
          return { ref: y(f) };
        const k = _[1].defId ?? _[1].schema.id ?? `schema${this.counter++}`;
        return _[1].defId = k, { defId: k, ref: `${y("__shared")}#/${m}/${k}` };
      }
      if (_[1] === s)
        return { ref: "#" };
      const g = `#/${m}/`, $ = _[1].schema.id ?? `__schema${this.counter++}`;
      return { defId: $, ref: g + $ };
    }, a = (_) => {
      if (_[1].schema.$ref)
        return;
      const m = _[1], { ref: h, defId: g } = i(_);
      m.def = { ...m.schema }, g && (m.defId = g);
      const $ = m.schema;
      for (const d in $)
        delete $[d];
      $.$ref = h;
    };
    if (n.cycles === "throw")
      for (const _ of this.seen.entries()) {
        const m = _[1];
        if (m.cycle)
          throw new Error(`Cycle detected: #/${(l = m.cycle) == null ? void 0 : l.join("/")}/<root>

Set the \`cycles\` parameter to \`"ref"\` to resolve cyclical schemas with defs.`);
      }
    for (const _ of this.seen.entries()) {
      const m = _[1];
      if (e === _[0]) {
        a(_);
        continue;
      }
      if (n.external) {
        const g = (p = n.external.registry.get(_[0])) == null ? void 0 : p.id;
        if (e !== _[0] && g) {
          a(_);
          continue;
        }
      }
      if ((v = this.metadataRegistry.get(_[0])) == null ? void 0 : v.id) {
        a(_);
        continue;
      }
      if (m.cycle) {
        a(_);
        continue;
      }
      if (m.count > 1 && n.reused === "ref") {
        a(_);
        continue;
      }
    }
    const o = (_, m) => {
      const h = this.seen.get(_), g = h.def ?? h.schema, $ = { ...g };
      if (h.ref === null)
        return;
      const d = h.ref;
      if (h.ref = null, d) {
        o(d, m);
        const f = this.seen.get(d).schema;
        f.$ref && m.target === "draft-7" ? (g.allOf = g.allOf ?? [], g.allOf.push(f)) : (Object.assign(g, f), Object.assign(g, $));
      }
      h.isParent || this.override({
        zodSchema: _,
        jsonSchema: g,
        path: h.path ?? []
      });
    };
    for (const _ of [...this.seen.entries()].reverse())
      o(_[0], { target: this.target });
    const c = {};
    if (this.target === "draft-2020-12" ? c.$schema = "https://json-schema.org/draft/2020-12/schema" : this.target === "draft-7" ? c.$schema = "http://json-schema.org/draft-07/schema#" : console.warn(`Invalid target: ${this.target}`), (w = n.external) != null && w.uri) {
      const _ = (b = n.external.registry.get(e)) == null ? void 0 : b.id;
      if (!_)
        throw new Error("Schema is missing an `id` property");
      c.$id = n.external.uri(_);
    }
    Object.assign(c, s.def);
    const u = ((E = n.external) == null ? void 0 : E.defs) ?? {};
    for (const _ of this.seen.entries()) {
      const m = _[1];
      m.def && m.defId && (u[m.defId] = m.def);
    }
    n.external || Object.keys(u).length > 0 && (this.target === "draft-2020-12" ? c.$defs = u : c.definitions = u);
    try {
      return JSON.parse(JSON.stringify(c));
    } catch {
      throw new Error("Error converting schema to JSON.");
    }
  }
}
function mf(t, e) {
  if (t instanceof eu) {
    const n = new $a(e), s = {};
    for (const o of t._idmap.entries()) {
      const [c, u] = o;
      n.process(u);
    }
    const i = {}, a = {
      registry: t,
      uri: e == null ? void 0 : e.uri,
      defs: s
    };
    for (const o of t._idmap.entries()) {
      const [c, u] = o;
      i[c] = n.emit(u, {
        ...e,
        external: a
      });
    }
    if (Object.keys(s).length > 0) {
      const o = n.target === "draft-2020-12" ? "$defs" : "definitions";
      i.__shared = {
        [o]: s
      };
    }
    return { schemas: i };
  }
  const r = new $a(e);
  return r.process(t), r.emit(t, e);
}
function Me(t, e) {
  const r = e ?? { seen: /* @__PURE__ */ new Set() };
  if (r.seen.has(t))
    return !1;
  r.seen.add(t);
  const s = t._zod.def;
  switch (s.type) {
    case "string":
    case "number":
    case "bigint":
    case "boolean":
    case "date":
    case "symbol":
    case "undefined":
    case "null":
    case "any":
    case "unknown":
    case "never":
    case "void":
    case "literal":
    case "enum":
    case "nan":
    case "file":
    case "template_literal":
      return !1;
    case "array":
      return Me(s.element, r);
    case "object": {
      for (const i in s.shape)
        if (Me(s.shape[i], r))
          return !0;
      return !1;
    }
    case "union": {
      for (const i of s.options)
        if (Me(i, r))
          return !0;
      return !1;
    }
    case "intersection":
      return Me(s.left, r) || Me(s.right, r);
    case "tuple": {
      for (const i of s.items)
        if (Me(i, r))
          return !0;
      return !!(s.rest && Me(s.rest, r));
    }
    case "record":
      return Me(s.keyType, r) || Me(s.valueType, r);
    case "map":
      return Me(s.keyType, r) || Me(s.valueType, r);
    case "set":
      return Me(s.valueType, r);
    // inner types
    case "promise":
    case "optional":
    case "nonoptional":
    case "nullable":
    case "readonly":
      return Me(s.innerType, r);
    case "lazy":
      return Me(s.getter(), r);
    case "default":
      return Me(s.innerType, r);
    case "prefault":
      return Me(s.innerType, r);
    case "custom":
      return !1;
    case "transform":
      return !0;
    case "pipe":
      return Me(s.in, r) || Me(s.out, r);
    case "success":
      return !1;
    case "catch":
      return !1;
  }
  throw new Error(`Unknown schema type: ${s.type}`);
}
const gf = /* @__PURE__ */ z("ZodISODateTime", (t, e) => {
  Ld.init(t, e), Ae.init(t, e);
});
function nu(t) {
  return Vh(gf, t);
}
const _f = /* @__PURE__ */ z("ZodISODate", (t, e) => {
  Zd.init(t, e), Ae.init(t, e);
});
function yf(t) {
  return Kh(_f, t);
}
const vf = /* @__PURE__ */ z("ZodISOTime", (t, e) => {
  Fd.init(t, e), Ae.init(t, e);
});
function wf(t) {
  return Gh(vf, t);
}
const bf = /* @__PURE__ */ z("ZodISODuration", (t, e) => {
  Hd.init(t, e), Ae.init(t, e);
});
function Sf(t) {
  return Wh(bf, t);
}
const kf = (t, e) => {
  Dc.init(t, e), t.name = "ZodError", Object.defineProperties(t, {
    format: {
      value: (r) => Dl(t, r)
      // enumerable: false,
    },
    flatten: {
      value: (r) => Ml(t, r)
      // enumerable: false,
    },
    addIssue: {
      value: (r) => t.issues.push(r)
      // enumerable: false,
    },
    addIssues: {
      value: (r) => t.issues.push(...r)
      // enumerable: false,
    },
    isEmpty: {
      get() {
        return t.issues.length === 0;
      }
      // enumerable: false,
    }
  });
}, cs = z("ZodError", kf, {
  Parent: Error
}), $f = /* @__PURE__ */ Uc(cs), Ef = /* @__PURE__ */ Lc(cs), Tf = /* @__PURE__ */ Zc(cs), Rf = /* @__PURE__ */ Fc(cs), Ce = /* @__PURE__ */ z("ZodType", (t, e) => (Ee.init(t, e), t.def = e, Object.defineProperty(t, "_def", { value: e }), t.check = (...r) => t.clone(
  {
    ...e,
    checks: [
      ...e.checks ?? [],
      ...r.map((n) => typeof n == "function" ? { _zod: { check: n, def: { check: "custom" }, onattach: [] } } : n)
    ]
  }
  // { parent: true }
), t.clone = (r, n) => Mt(t, r, n), t.brand = () => t, t.register = ((r, n) => (r.add(t, n), t)), t.parse = (r, n) => $f(t, r, n, { callee: t.parse }), t.safeParse = (r, n) => Tf(t, r, n), t.parseAsync = async (r, n) => Ef(t, r, n, { callee: t.parseAsync }), t.safeParseAsync = async (r, n) => Rf(t, r, n), t.spa = t.safeParseAsync, t.refine = (r, n) => t.check(yp(r, n)), t.superRefine = (r) => t.check(vp(r)), t.overwrite = (r) => t.check(zr(r)), t.optional = () => Pe(t), t.nullable = () => Ra(t), t.nullish = () => Pe(Ra(t)), t.nonoptional = (r) => lp(t, r), t.array = () => he(t), t.or = (r) => Ie([t, r]), t.and = (r) => Ci(t, r), t.transform = (r) => Ws(t, uu(r)), t.default = (r) => op(t, r), t.prefault = (r) => up(t, r), t.catch = (r) => hp(t, r), t.pipe = (r) => Ws(t, r), t.readonly = () => mp(t), t.describe = (r) => {
  const n = t.clone();
  return Sr.add(n, { description: r }), n;
}, Object.defineProperty(t, "description", {
  get() {
    var r;
    return (r = Sr.get(t)) == null ? void 0 : r.description;
  },
  configurable: !0
}), t.meta = (...r) => {
  if (r.length === 0)
    return Sr.get(t);
  const n = t.clone();
  return Sr.add(n, r[0]), n;
}, t.isOptional = () => t.safeParse(void 0).success, t.isNullable = () => t.safeParse(null).success, t)), su = /* @__PURE__ */ z("_ZodString", (t, e) => {
  Oi.init(t, e), Ce.init(t, e);
  const r = t._zod.bag;
  t.format = r.format ?? null, t.minLength = r.minimum ?? null, t.maxLength = r.maximum ?? null, t.regex = (...n) => t.check(tf(...n)), t.includes = (...n) => t.check(sf(...n)), t.startsWith = (...n) => t.check(af(...n)), t.endsWith = (...n) => t.check(of(...n)), t.min = (...n) => t.check(Un(...n)), t.max = (...n) => t.check(tu(...n)), t.length = (...n) => t.check(ru(...n)), t.nonempty = (...n) => t.check(Un(1, ...n)), t.lowercase = (n) => t.check(rf(n)), t.uppercase = (n) => t.check(nf(n)), t.trim = () => t.check(uf()), t.normalize = (...n) => t.check(cf(...n)), t.toLowerCase = () => t.check(lf()), t.toUpperCase = () => t.check(df());
}), If = /* @__PURE__ */ z("ZodString", (t, e) => {
  Oi.init(t, e), su.init(t, e), t.email = (r) => t.check($h(Pf, r)), t.url = (r) => t.check(Ph(Of, r)), t.jwt = (r) => t.check(Hh(Vf, r)), t.emoji = (r) => t.check(Oh(Cf, r)), t.guid = (r) => t.check(wa(Ea, r)), t.uuid = (r) => t.check(Eh(Kr, r)), t.uuidv4 = (r) => t.check(Th(Kr, r)), t.uuidv6 = (r) => t.check(Rh(Kr, r)), t.uuidv7 = (r) => t.check(Ih(Kr, r)), t.nanoid = (r) => t.check(Ch(Af, r)), t.guid = (r) => t.check(wa(Ea, r)), t.cuid = (r) => t.check(Ah(Nf, r)), t.cuid2 = (r) => t.check(Nh(xf, r)), t.ulid = (r) => t.check(xh(jf, r)), t.base64 = (r) => t.check(Lh(Zf, r)), t.base64url = (r) => t.check(Zh(Ff, r)), t.xid = (r) => t.check(jh(zf, r)), t.ksuid = (r) => t.check(zh(qf, r)), t.ipv4 = (r) => t.check(qh(Mf, r)), t.ipv6 = (r) => t.check(Mh(Df, r)), t.cidrv4 = (r) => t.check(Dh(Uf, r)), t.cidrv6 = (r) => t.check(Uh(Lf, r)), t.e164 = (r) => t.check(Fh(Hf, r)), t.datetime = (r) => t.check(nu(r)), t.date = (r) => t.check(yf(r)), t.time = (r) => t.check(wf(r)), t.duration = (r) => t.check(Sf(r));
});
function x(t) {
  return kh(If, t);
}
const Ae = /* @__PURE__ */ z("ZodStringFormat", (t, e) => {
  Re.init(t, e), su.init(t, e);
}), Pf = /* @__PURE__ */ z("ZodEmail", (t, e) => {
  Ad.init(t, e), Ae.init(t, e);
}), Ea = /* @__PURE__ */ z("ZodGUID", (t, e) => {
  Od.init(t, e), Ae.init(t, e);
}), Kr = /* @__PURE__ */ z("ZodUUID", (t, e) => {
  Cd.init(t, e), Ae.init(t, e);
}), Of = /* @__PURE__ */ z("ZodURL", (t, e) => {
  Nd.init(t, e), Ae.init(t, e);
}), Cf = /* @__PURE__ */ z("ZodEmoji", (t, e) => {
  xd.init(t, e), Ae.init(t, e);
}), Af = /* @__PURE__ */ z("ZodNanoID", (t, e) => {
  jd.init(t, e), Ae.init(t, e);
}), Nf = /* @__PURE__ */ z("ZodCUID", (t, e) => {
  zd.init(t, e), Ae.init(t, e);
}), xf = /* @__PURE__ */ z("ZodCUID2", (t, e) => {
  qd.init(t, e), Ae.init(t, e);
}), jf = /* @__PURE__ */ z("ZodULID", (t, e) => {
  Md.init(t, e), Ae.init(t, e);
}), zf = /* @__PURE__ */ z("ZodXID", (t, e) => {
  Dd.init(t, e), Ae.init(t, e);
}), qf = /* @__PURE__ */ z("ZodKSUID", (t, e) => {
  Ud.init(t, e), Ae.init(t, e);
}), Mf = /* @__PURE__ */ z("ZodIPv4", (t, e) => {
  Vd.init(t, e), Ae.init(t, e);
}), Df = /* @__PURE__ */ z("ZodIPv6", (t, e) => {
  Kd.init(t, e), Ae.init(t, e);
}), Uf = /* @__PURE__ */ z("ZodCIDRv4", (t, e) => {
  Gd.init(t, e), Ae.init(t, e);
}), Lf = /* @__PURE__ */ z("ZodCIDRv6", (t, e) => {
  Wd.init(t, e), Ae.init(t, e);
}), Zf = /* @__PURE__ */ z("ZodBase64", (t, e) => {
  Jd.init(t, e), Ae.init(t, e);
}), Ff = /* @__PURE__ */ z("ZodBase64URL", (t, e) => {
  Qd.init(t, e), Ae.init(t, e);
}), Hf = /* @__PURE__ */ z("ZodE164", (t, e) => {
  Yd.init(t, e), Ae.init(t, e);
}), Vf = /* @__PURE__ */ z("ZodJWT", (t, e) => {
  eh.init(t, e), Ae.init(t, e);
}), iu = /* @__PURE__ */ z("ZodNumber", (t, e) => {
  Qc.init(t, e), Ce.init(t, e), t.gt = (n, s) => t.check(Sa(n, s)), t.gte = (n, s) => t.check(bs(n, s)), t.min = (n, s) => t.check(bs(n, s)), t.lt = (n, s) => t.check(ba(n, s)), t.lte = (n, s) => t.check(ws(n, s)), t.max = (n, s) => t.check(ws(n, s)), t.int = (n) => t.check(Ta(n)), t.safe = (n) => t.check(Ta(n)), t.positive = (n) => t.check(Sa(0, n)), t.nonnegative = (n) => t.check(bs(0, n)), t.negative = (n) => t.check(ba(0, n)), t.nonpositive = (n) => t.check(ws(0, n)), t.multipleOf = (n, s) => t.check(ka(n, s)), t.step = (n, s) => t.check(ka(n, s)), t.finite = () => t;
  const r = t._zod.bag;
  t.minValue = Math.max(r.minimum ?? Number.NEGATIVE_INFINITY, r.exclusiveMinimum ?? Number.NEGATIVE_INFINITY) ?? null, t.maxValue = Math.min(r.maximum ?? Number.POSITIVE_INFINITY, r.exclusiveMaximum ?? Number.POSITIVE_INFINITY) ?? null, t.isInt = (r.format ?? "").includes("int") || Number.isSafeInteger(r.multipleOf ?? 0.5), t.isFinite = !0, t.format = r.format ?? null;
});
function Se(t) {
  return Jh(iu, t);
}
const Kf = /* @__PURE__ */ z("ZodNumberFormat", (t, e) => {
  th.init(t, e), iu.init(t, e);
});
function Ta(t) {
  return Bh(Kf, t);
}
const Gf = /* @__PURE__ */ z("ZodBoolean", (t, e) => {
  rh.init(t, e), Ce.init(t, e);
});
function Fe(t) {
  return Qh(Gf, t);
}
const Wf = /* @__PURE__ */ z("ZodNull", (t, e) => {
  nh.init(t, e), Ce.init(t, e);
});
function Jf(t) {
  return Yh(Wf, t);
}
const Bf = /* @__PURE__ */ z("ZodUnknown", (t, e) => {
  sh.init(t, e), Ce.init(t, e);
});
function Oe() {
  return Xh(Bf);
}
const Qf = /* @__PURE__ */ z("ZodNever", (t, e) => {
  ih.init(t, e), Ce.init(t, e);
});
function Yf(t) {
  return ef(Qf, t);
}
const Xf = /* @__PURE__ */ z("ZodArray", (t, e) => {
  ah.init(t, e), Ce.init(t, e), t.element = e.element, t.min = (r, n) => t.check(Un(r, n)), t.nonempty = (r) => t.check(Un(1, r)), t.max = (r, n) => t.check(tu(r, n)), t.length = (r, n) => t.check(ru(r, n)), t.unwrap = () => t.element;
});
function he(t, e) {
  return hf(Xf, t, e);
}
const au = /* @__PURE__ */ z("ZodObject", (t, e) => {
  Yc.init(t, e), Ce.init(t, e), $e(t, "shape", () => e.shape), t.keyof = () => rt(Object.keys(t._zod.def.shape)), t.catchall = (r) => t.clone({ ...t._zod.def, catchall: r }), t.passthrough = () => t.clone({ ...t._zod.def, catchall: Oe() }), t.loose = () => t.clone({ ...t._zod.def, catchall: Oe() }), t.strict = () => t.clone({ ...t._zod.def, catchall: Yf() }), t.strip = () => t.clone({ ...t._zod.def, catchall: void 0 }), t.extend = (r) => xl(t, r), t.merge = (r) => jl(t, r), t.pick = (r) => Al(t, r), t.omit = (r) => Nl(t, r), t.partial = (...r) => zl(lu, t, r[0]), t.required = (...r) => ql(du, t, r[0]);
});
function G(t, e) {
  const r = {
    type: "object",
    get shape() {
      return xr(this, "shape", { ...t }), this.shape;
    },
    ...Q(e)
  };
  return new au(r);
}
function Ye(t, e) {
  return new au({
    type: "object",
    get shape() {
      return xr(this, "shape", { ...t }), this.shape;
    },
    catchall: Oe(),
    ...Q(e)
  });
}
const ou = /* @__PURE__ */ z("ZodUnion", (t, e) => {
  Xc.init(t, e), Ce.init(t, e), t.options = e.options;
});
function Ie(t, e) {
  return new ou({
    type: "union",
    options: t,
    ...Q(e)
  });
}
const ep = /* @__PURE__ */ z("ZodDiscriminatedUnion", (t, e) => {
  ou.init(t, e), oh.init(t, e);
});
function cu(t, e, r) {
  return new ep({
    type: "union",
    options: e,
    discriminator: t,
    ...Q(r)
  });
}
const tp = /* @__PURE__ */ z("ZodIntersection", (t, e) => {
  ch.init(t, e), Ce.init(t, e);
});
function Ci(t, e) {
  return new tp({
    type: "intersection",
    left: t,
    right: e
  });
}
const rp = /* @__PURE__ */ z("ZodRecord", (t, e) => {
  uh.init(t, e), Ce.init(t, e), t.keyType = e.keyType, t.valueType = e.valueType;
});
function Te(t, e, r) {
  return new rp({
    type: "record",
    keyType: t,
    valueType: e,
    ...Q(r)
  });
}
const Gs = /* @__PURE__ */ z("ZodEnum", (t, e) => {
  lh.init(t, e), Ce.init(t, e), t.enum = e.entries, t.options = Object.values(e.entries);
  const r = new Set(Object.keys(e.entries));
  t.extract = (n, s) => {
    const i = {};
    for (const a of n)
      if (r.has(a))
        i[a] = e.entries[a];
      else
        throw new Error(`Key ${a} not found in enum`);
    return new Gs({
      ...e,
      checks: [],
      ...Q(s),
      entries: i
    });
  }, t.exclude = (n, s) => {
    const i = { ...e.entries };
    for (const a of n)
      if (r.has(a))
        delete i[a];
      else
        throw new Error(`Key ${a} not found in enum`);
    return new Gs({
      ...e,
      checks: [],
      ...Q(s),
      entries: i
    });
  };
});
function rt(t, e) {
  const r = Array.isArray(t) ? Object.fromEntries(t.map((n) => [n, n])) : t;
  return new Gs({
    type: "enum",
    entries: r,
    ...Q(e)
  });
}
const np = /* @__PURE__ */ z("ZodLiteral", (t, e) => {
  dh.init(t, e), Ce.init(t, e), t.values = new Set(e.values), Object.defineProperty(t, "value", {
    get() {
      if (e.values.length > 1)
        throw new Error("This schema contains multiple valid literal values. Use `.values` instead.");
      return e.values[0];
    }
  });
});
function ee(t, e) {
  return new np({
    type: "literal",
    values: Array.isArray(t) ? t : [t],
    ...Q(e)
  });
}
const sp = /* @__PURE__ */ z("ZodTransform", (t, e) => {
  hh.init(t, e), Ce.init(t, e), t._zod.parse = (r, n) => {
    r.addIssue = (i) => {
      if (typeof i == "string")
        r.issues.push(Pr(i, r.value, e));
      else {
        const a = i;
        a.fatal && (a.continue = !1), a.code ?? (a.code = "custom"), a.input ?? (a.input = r.value), a.inst ?? (a.inst = t), a.continue ?? (a.continue = !0), r.issues.push(Pr(a));
      }
    };
    const s = e.transform(r.value, r);
    return s instanceof Promise ? s.then((i) => (r.value = i, r)) : (r.value = s, r);
  };
});
function uu(t) {
  return new sp({
    type: "transform",
    transform: t
  });
}
const lu = /* @__PURE__ */ z("ZodOptional", (t, e) => {
  fh.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType;
});
function Pe(t) {
  return new lu({
    type: "optional",
    innerType: t
  });
}
const ip = /* @__PURE__ */ z("ZodNullable", (t, e) => {
  ph.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType;
});
function Ra(t) {
  return new ip({
    type: "nullable",
    innerType: t
  });
}
const ap = /* @__PURE__ */ z("ZodDefault", (t, e) => {
  mh.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType, t.removeDefault = t.unwrap;
});
function op(t, e) {
  return new ap({
    type: "default",
    innerType: t,
    get defaultValue() {
      return typeof e == "function" ? e() : e;
    }
  });
}
const cp = /* @__PURE__ */ z("ZodPrefault", (t, e) => {
  gh.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType;
});
function up(t, e) {
  return new cp({
    type: "prefault",
    innerType: t,
    get defaultValue() {
      return typeof e == "function" ? e() : e;
    }
  });
}
const du = /* @__PURE__ */ z("ZodNonOptional", (t, e) => {
  _h.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType;
});
function lp(t, e) {
  return new du({
    type: "nonoptional",
    innerType: t,
    ...Q(e)
  });
}
const dp = /* @__PURE__ */ z("ZodCatch", (t, e) => {
  yh.init(t, e), Ce.init(t, e), t.unwrap = () => t._zod.def.innerType, t.removeCatch = t.unwrap;
});
function hp(t, e) {
  return new dp({
    type: "catch",
    innerType: t,
    catchValue: typeof e == "function" ? e : () => e
  });
}
const fp = /* @__PURE__ */ z("ZodPipe", (t, e) => {
  vh.init(t, e), Ce.init(t, e), t.in = e.in, t.out = e.out;
});
function Ws(t, e) {
  return new fp({
    type: "pipe",
    in: t,
    out: e
    // ...util.normalizeParams(params),
  });
}
const pp = /* @__PURE__ */ z("ZodReadonly", (t, e) => {
  wh.init(t, e), Ce.init(t, e);
});
function mp(t) {
  return new pp({
    type: "readonly",
    innerType: t
  });
}
const hu = /* @__PURE__ */ z("ZodCustom", (t, e) => {
  bh.init(t, e), Ce.init(t, e);
});
function gp(t) {
  const e = new et({
    check: "custom"
    // ...util.normalizeParams(params),
  });
  return e._zod.check = t, e;
}
function _p(t, e) {
  return ff(hu, t ?? (() => !0), e);
}
function yp(t, e = {}) {
  return pf(hu, t, e);
}
function vp(t) {
  const e = gp((r) => (r.addIssue = (n) => {
    if (typeof n == "string")
      r.issues.push(Pr(n, r.value, e._zod.def));
    else {
      const s = n;
      s.fatal && (s.continue = !1), s.code ?? (s.code = "custom"), s.input ?? (s.input = r.value), s.inst ?? (s.inst = e), s.continue ?? (s.continue = !e._zod.def.abort), r.issues.push(Pr(s));
    }
  }, t(r.value, r)));
  return e;
}
function fu(t, e) {
  return Ws(uu(t), e);
}
const pu = "2025-11-25", wp = [pu, "2025-06-18", "2025-03-26", "2024-11-05", "2024-10-07"], Vt = "io.modelcontextprotocol/related-task", us = "2.0", De = _p((t) => t !== null && (typeof t == "object" || typeof t == "function")), mu = Ie([x(), Se().int()]), gu = x();
Ye({
  /**
   * Requested duration in milliseconds to retain task from creation.
   */
  ttl: Se().optional(),
  /**
   * Time in milliseconds to wait between task status requests.
   */
  pollInterval: Se().optional()
});
const bp = G({
  ttl: Se().optional()
}), Sp = G({
  taskId: x()
}), Ai = Ye({
  /**
   * If specified, the caller is requesting out-of-band progress notifications for this request (as represented by notifications/progress). The value of this parameter is an opaque token that will be attached to any subsequent notifications. The receiver is not obligated to provide these notifications.
   */
  progressToken: mu.optional(),
  /**
   * If specified, this request is related to the provided task.
   */
  [Vt]: Sp.optional()
}), nt = G({
  /**
   * See [General fields: `_meta`](/specification/draft/basic/index#meta) for notes on `_meta` usage.
   */
  _meta: Ai.optional()
}), qr = nt.extend({
  /**
   * If specified, the caller is requesting task-augmented execution for this request.
   * The request will return a CreateTaskResult immediately, and the actual result can be
   * retrieved later via tasks/result.
   *
   * Task augmentation is subject to capability negotiation - receivers MUST declare support
   * for task augmentation of specific request types in their capabilities.
   */
  task: bp.optional()
}), kp = (t) => qr.safeParse(t).success, He = G({
  method: x(),
  params: nt.loose().optional()
}), at = G({
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Ai.optional()
}), ot = G({
  method: x(),
  params: at.loose().optional()
}), Ve = Ye({
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Ai.optional()
}), ls = Ie([x(), Se().int()]), _u = G({
  jsonrpc: ee(us),
  id: ls,
  ...He.shape
}).strict(), Ia = (t) => _u.safeParse(t).success, yu = G({
  jsonrpc: ee(us),
  ...ot.shape
}).strict(), $p = (t) => yu.safeParse(t).success, Ni = G({
  jsonrpc: ee(us),
  id: ls,
  result: Ve
}).strict(), Gr = (t) => Ni.safeParse(t).success;
var re;
(function(t) {
  t[t.ConnectionClosed = -32e3] = "ConnectionClosed", t[t.RequestTimeout = -32001] = "RequestTimeout", t[t.ParseError = -32700] = "ParseError", t[t.InvalidRequest = -32600] = "InvalidRequest", t[t.MethodNotFound = -32601] = "MethodNotFound", t[t.InvalidParams = -32602] = "InvalidParams", t[t.InternalError = -32603] = "InternalError", t[t.UrlElicitationRequired = -32042] = "UrlElicitationRequired";
})(re || (re = {}));
const xi = G({
  jsonrpc: ee(us),
  id: ls.optional(),
  error: G({
    /**
     * The error type that occurred.
     */
    code: Se().int(),
    /**
     * A short description of the error. The message SHOULD be limited to a concise single sentence.
     */
    message: x(),
    /**
     * Additional information about the error. The value of this member is defined by the sender (e.g. detailed error information, nested errors etc.).
     */
    data: Oe().optional()
  })
}).strict(), Ep = (t) => xi.safeParse(t).success, Tp = Ie([
  _u,
  yu,
  Ni,
  xi
]);
Ie([Ni, xi]);
const ji = Ve.strict(), Rp = at.extend({
  /**
   * The ID of the request to cancel.
   *
   * This MUST correspond to the ID of a request previously issued in the same direction.
   */
  requestId: ls.optional(),
  /**
   * An optional string describing the reason for the cancellation. This MAY be logged or presented to the user.
   */
  reason: x().optional()
}), zi = ot.extend({
  method: ee("notifications/cancelled"),
  params: Rp
}), Ip = G({
  /**
   * URL or data URI for the icon.
   */
  src: x(),
  /**
   * Optional MIME type for the icon.
   */
  mimeType: x().optional(),
  /**
   * Optional array of strings that specify sizes at which the icon can be used.
   * Each string should be in WxH format (e.g., `"48x48"`, `"96x96"`) or `"any"` for scalable formats like SVG.
   *
   * If not provided, the client should assume that the icon can be used at any size.
   */
  sizes: he(x()).optional(),
  /**
   * Optional specifier for the theme this icon is designed for. `light` indicates
   * the icon is designed to be used with a light background, and `dark` indicates
   * the icon is designed to be used with a dark background.
   *
   * If not provided, the client should assume the icon can be used with any theme.
   */
  theme: rt(["light", "dark"]).optional()
}), Mr = G({
  /**
   * Optional set of sized icons that the client can display in a user interface.
   *
   * Clients that support rendering icons MUST support at least the following MIME types:
   * - `image/png` - PNG images (safe, universal compatibility)
   * - `image/jpeg` (and `image/jpg`) - JPEG images (safe, universal compatibility)
   *
   * Clients that support rendering icons SHOULD also support:
   * - `image/svg+xml` - SVG images (scalable but requires security precautions)
   * - `image/webp` - WebP images (modern, efficient format)
   */
  icons: he(Ip).optional()
}), ir = G({
  /** Intended for programmatic or logical use, but used as a display name in past specs or fallback */
  name: x(),
  /**
   * Intended for UI and end-user contexts — optimized to be human-readable and easily understood,
   * even by those unfamiliar with domain-specific terminology.
   *
   * If not provided, the name should be used for display (except for Tool,
   * where `annotations.title` should be given precedence over using `name`,
   * if present).
   */
  title: x().optional()
}), vu = ir.extend({
  ...ir.shape,
  ...Mr.shape,
  version: x(),
  /**
   * An optional URL of the website for this implementation.
   */
  websiteUrl: x().optional(),
  /**
   * An optional human-readable description of what this implementation does.
   *
   * This can be used by clients or servers to provide context about their purpose
   * and capabilities. For example, a server might describe the types of resources
   * or tools it provides, while a client might describe its intended use case.
   */
  description: x().optional()
}), Pp = Ci(G({
  applyDefaults: Fe().optional()
}), Te(x(), Oe())), Op = fu((t) => t && typeof t == "object" && !Array.isArray(t) && Object.keys(t).length === 0 ? { form: {} } : t, Ci(G({
  form: Pp.optional(),
  url: De.optional()
}), Te(x(), Oe()).optional())), Cp = Ye({
  /**
   * Present if the client supports listing tasks.
   */
  list: De.optional(),
  /**
   * Present if the client supports cancelling tasks.
   */
  cancel: De.optional(),
  /**
   * Capabilities for task creation on specific request types.
   */
  requests: Ye({
    /**
     * Task support for sampling requests.
     */
    sampling: Ye({
      createMessage: De.optional()
    }).optional(),
    /**
     * Task support for elicitation requests.
     */
    elicitation: Ye({
      create: De.optional()
    }).optional()
  }).optional()
}), Ap = Ye({
  /**
   * Present if the server supports listing tasks.
   */
  list: De.optional(),
  /**
   * Present if the server supports cancelling tasks.
   */
  cancel: De.optional(),
  /**
   * Capabilities for task creation on specific request types.
   */
  requests: Ye({
    /**
     * Task support for tool requests.
     */
    tools: Ye({
      call: De.optional()
    }).optional()
  }).optional()
}), Np = G({
  /**
   * Experimental, non-standard capabilities that the client supports.
   */
  experimental: Te(x(), De).optional(),
  /**
   * Present if the client supports sampling from an LLM.
   */
  sampling: G({
    /**
     * Present if the client supports context inclusion via includeContext parameter.
     * If not declared, servers SHOULD only use `includeContext: "none"` (or omit it).
     */
    context: De.optional(),
    /**
     * Present if the client supports tool use via tools and toolChoice parameters.
     */
    tools: De.optional()
  }).optional(),
  /**
   * Present if the client supports eliciting user input.
   */
  elicitation: Op.optional(),
  /**
   * Present if the client supports listing roots.
   */
  roots: G({
    /**
     * Whether the client supports issuing notifications for changes to the roots list.
     */
    listChanged: Fe().optional()
  }).optional(),
  /**
   * Present if the client supports task creation.
   */
  tasks: Cp.optional(),
  /**
   * Extensions that the client supports. Keys are extension identifiers (vendor-prefix/extension-name).
   */
  extensions: Te(x(), De).optional()
}), xp = nt.extend({
  /**
   * The latest version of the Model Context Protocol that the client supports. The client MAY decide to support older versions as well.
   */
  protocolVersion: x(),
  capabilities: Np,
  clientInfo: vu
}), wu = He.extend({
  method: ee("initialize"),
  params: xp
}), jp = G({
  /**
   * Experimental, non-standard capabilities that the server supports.
   */
  experimental: Te(x(), De).optional(),
  /**
   * Present if the server supports sending log messages to the client.
   */
  logging: De.optional(),
  /**
   * Present if the server supports sending completions to the client.
   */
  completions: De.optional(),
  /**
   * Present if the server offers any prompt templates.
   */
  prompts: G({
    /**
     * Whether this server supports issuing notifications for changes to the prompt list.
     */
    listChanged: Fe().optional()
  }).optional(),
  /**
   * Present if the server offers any resources to read.
   */
  resources: G({
    /**
     * Whether this server supports clients subscribing to resource updates.
     */
    subscribe: Fe().optional(),
    /**
     * Whether this server supports issuing notifications for changes to the resource list.
     */
    listChanged: Fe().optional()
  }).optional(),
  /**
   * Present if the server offers any tools to call.
   */
  tools: G({
    /**
     * Whether this server supports issuing notifications for changes to the tool list.
     */
    listChanged: Fe().optional()
  }).optional(),
  /**
   * Present if the server supports task creation.
   */
  tasks: Ap.optional(),
  /**
   * Extensions that the server supports. Keys are extension identifiers (vendor-prefix/extension-name).
   */
  extensions: Te(x(), De).optional()
}), zp = Ve.extend({
  /**
   * The version of the Model Context Protocol that the server wants to use. This may not match the version that the client requested. If the client cannot support this version, it MUST disconnect.
   */
  protocolVersion: x(),
  capabilities: jp,
  serverInfo: vu,
  /**
   * Instructions describing how to use the server and its features.
   *
   * This can be used by clients to improve the LLM's understanding of available tools, resources, etc. It can be thought of like a "hint" to the model. For example, this information MAY be added to the system prompt.
   */
  instructions: x().optional()
}), bu = ot.extend({
  method: ee("notifications/initialized"),
  params: at.optional()
}), qi = He.extend({
  method: ee("ping"),
  params: nt.optional()
}), qp = G({
  /**
   * The progress thus far. This should increase every time progress is made, even if the total is unknown.
   */
  progress: Se(),
  /**
   * Total number of items to process (or total progress required), if known.
   */
  total: Pe(Se()),
  /**
   * An optional message describing the current progress.
   */
  message: Pe(x())
}), Mp = G({
  ...at.shape,
  ...qp.shape,
  /**
   * The progress token which was given in the initial request, used to associate this notification with the request that is proceeding.
   */
  progressToken: mu
}), Mi = ot.extend({
  method: ee("notifications/progress"),
  params: Mp
}), Dp = nt.extend({
  /**
   * An opaque token representing the current pagination position.
   * If provided, the server should return results starting after this cursor.
   */
  cursor: gu.optional()
}), Dr = He.extend({
  params: Dp.optional()
}), Ur = Ve.extend({
  /**
   * An opaque token representing the pagination position after the last returned result.
   * If present, there may be more results available.
   */
  nextCursor: gu.optional()
}), Up = rt(["working", "input_required", "completed", "failed", "cancelled"]), Lr = G({
  taskId: x(),
  status: Up,
  /**
   * Time in milliseconds to keep task results available after completion.
   * If null, the task has unlimited lifetime until manually cleaned up.
   */
  ttl: Ie([Se(), Jf()]),
  /**
   * ISO 8601 timestamp when the task was created.
   */
  createdAt: x(),
  /**
   * ISO 8601 timestamp when the task was last updated.
   */
  lastUpdatedAt: x(),
  pollInterval: Pe(Se()),
  /**
   * Optional diagnostic message for failed tasks or other status information.
   */
  statusMessage: Pe(x())
}), ds = Ve.extend({
  task: Lr
}), Lp = at.merge(Lr), Ln = ot.extend({
  method: ee("notifications/tasks/status"),
  params: Lp
}), Di = He.extend({
  method: ee("tasks/get"),
  params: nt.extend({
    taskId: x()
  })
}), Ui = Ve.merge(Lr), Li = He.extend({
  method: ee("tasks/result"),
  params: nt.extend({
    taskId: x()
  })
});
Ve.loose();
const Zi = Dr.extend({
  method: ee("tasks/list")
}), Fi = Ur.extend({
  tasks: he(Lr)
}), Hi = He.extend({
  method: ee("tasks/cancel"),
  params: nt.extend({
    taskId: x()
  })
}), Zp = Ve.merge(Lr), Su = G({
  /**
   * The URI of this resource.
   */
  uri: x(),
  /**
   * The MIME type of this resource, if known.
   */
  mimeType: Pe(x()),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), ku = Su.extend({
  /**
   * The text of the item. This must only be set if the item can actually be represented as text (not binary data).
   */
  text: x()
}), Vi = x().refine((t) => {
  try {
    return atob(t), !0;
  } catch {
    return !1;
  }
}, { message: "Invalid Base64 string" }), $u = Su.extend({
  /**
   * A base64-encoded string representing the binary data of the item.
   */
  blob: Vi
}), Zr = rt(["user", "assistant"]), hr = G({
  /**
   * Intended audience(s) for the resource.
   */
  audience: he(Zr).optional(),
  /**
   * Importance hint for the resource, from 0 (least) to 1 (most).
   */
  priority: Se().min(0).max(1).optional(),
  /**
   * ISO 8601 timestamp for the most recent modification.
   */
  lastModified: nu({ offset: !0 }).optional()
}), Eu = G({
  ...ir.shape,
  ...Mr.shape,
  /**
   * The URI of this resource.
   */
  uri: x(),
  /**
   * A description of what this resource represents.
   *
   * This can be used by clients to improve the LLM's understanding of available resources. It can be thought of like a "hint" to the model.
   */
  description: Pe(x()),
  /**
   * The MIME type of this resource, if known.
   */
  mimeType: Pe(x()),
  /**
   * The size of the raw resource content, in bytes (i.e., before base64 encoding or any tokenization), if known.
   *
   * This can be used by Hosts to display file sizes and estimate context window usage.
   */
  size: Pe(Se()),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Pe(Ye({}))
}), Fp = G({
  ...ir.shape,
  ...Mr.shape,
  /**
   * A URI template (according to RFC 6570) that can be used to construct resource URIs.
   */
  uriTemplate: x(),
  /**
   * A description of what this template is for.
   *
   * This can be used by clients to improve the LLM's understanding of available resources. It can be thought of like a "hint" to the model.
   */
  description: Pe(x()),
  /**
   * The MIME type for all resources that match this template. This should only be included if all resources matching this template have the same type.
   */
  mimeType: Pe(x()),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Pe(Ye({}))
}), Js = Dr.extend({
  method: ee("resources/list")
}), Hp = Ur.extend({
  resources: he(Eu)
}), Bs = Dr.extend({
  method: ee("resources/templates/list")
}), Vp = Ur.extend({
  resourceTemplates: he(Fp)
}), Ki = nt.extend({
  /**
   * The URI of the resource to read. The URI can use any protocol; it is up to the server how to interpret it.
   *
   * @format uri
   */
  uri: x()
}), Kp = Ki, Qs = He.extend({
  method: ee("resources/read"),
  params: Kp
}), Gp = Ve.extend({
  contents: he(Ie([ku, $u]))
}), Wp = ot.extend({
  method: ee("notifications/resources/list_changed"),
  params: at.optional()
}), Jp = Ki, Bp = He.extend({
  method: ee("resources/subscribe"),
  params: Jp
}), Qp = Ki, Yp = He.extend({
  method: ee("resources/unsubscribe"),
  params: Qp
}), Xp = at.extend({
  /**
   * The URI of the resource that has been updated. This might be a sub-resource of the one that the client actually subscribed to.
   */
  uri: x()
}), em = ot.extend({
  method: ee("notifications/resources/updated"),
  params: Xp
}), tm = G({
  /**
   * The name of the argument.
   */
  name: x(),
  /**
   * A human-readable description of the argument.
   */
  description: Pe(x()),
  /**
   * Whether this argument must be provided.
   */
  required: Pe(Fe())
}), rm = G({
  ...ir.shape,
  ...Mr.shape,
  /**
   * An optional description of what this prompt provides
   */
  description: Pe(x()),
  /**
   * A list of arguments to use for templating the prompt.
   */
  arguments: Pe(he(tm)),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Pe(Ye({}))
}), Ys = Dr.extend({
  method: ee("prompts/list")
}), nm = Ur.extend({
  prompts: he(rm)
}), sm = nt.extend({
  /**
   * The name of the prompt or prompt template.
   */
  name: x(),
  /**
   * Arguments to use for templating the prompt.
   */
  arguments: Te(x(), x()).optional()
}), Xs = He.extend({
  method: ee("prompts/get"),
  params: sm
}), Gi = G({
  type: ee("text"),
  /**
   * The text content of the message.
   */
  text: x(),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), Wi = G({
  type: ee("image"),
  /**
   * The base64-encoded image data.
   */
  data: Vi,
  /**
   * The MIME type of the image. Different providers may support different image types.
   */
  mimeType: x(),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), Ji = G({
  type: ee("audio"),
  /**
   * The base64-encoded audio data.
   */
  data: Vi,
  /**
   * The MIME type of the audio. Different providers may support different audio types.
   */
  mimeType: x(),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), im = G({
  type: ee("tool_use"),
  /**
   * The name of the tool to invoke.
   * Must match a tool name from the request's tools array.
   */
  name: x(),
  /**
   * Unique identifier for this tool call.
   * Used to correlate with ToolResultContent in subsequent messages.
   */
  id: x(),
  /**
   * Arguments to pass to the tool.
   * Must conform to the tool's inputSchema.
   */
  input: Te(x(), Oe()),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), am = G({
  type: ee("resource"),
  resource: Ie([ku, $u]),
  /**
   * Optional annotations for the client.
   */
  annotations: hr.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), om = Eu.extend({
  type: ee("resource_link")
}), Bi = Ie([
  Gi,
  Wi,
  Ji,
  om,
  am
]), cm = G({
  role: Zr,
  content: Bi
}), um = Ve.extend({
  /**
   * An optional description for the prompt.
   */
  description: x().optional(),
  messages: he(cm)
}), lm = ot.extend({
  method: ee("notifications/prompts/list_changed"),
  params: at.optional()
}), dm = G({
  /**
   * A human-readable title for the tool.
   */
  title: x().optional(),
  /**
   * If true, the tool does not modify its environment.
   *
   * Default: false
   */
  readOnlyHint: Fe().optional(),
  /**
   * If true, the tool may perform destructive updates to its environment.
   * If false, the tool performs only additive updates.
   *
   * (This property is meaningful only when `readOnlyHint == false`)
   *
   * Default: true
   */
  destructiveHint: Fe().optional(),
  /**
   * If true, calling the tool repeatedly with the same arguments
   * will have no additional effect on the its environment.
   *
   * (This property is meaningful only when `readOnlyHint == false`)
   *
   * Default: false
   */
  idempotentHint: Fe().optional(),
  /**
   * If true, this tool may interact with an "open world" of external
   * entities. If false, the tool's domain of interaction is closed.
   * For example, the world of a web search tool is open, whereas that
   * of a memory tool is not.
   *
   * Default: true
   */
  openWorldHint: Fe().optional()
}), hm = G({
  /**
   * Indicates the tool's preference for task-augmented execution.
   * - "required": Clients MUST invoke the tool as a task
   * - "optional": Clients MAY invoke the tool as a task or normal request
   * - "forbidden": Clients MUST NOT attempt to invoke the tool as a task
   *
   * If not present, defaults to "forbidden".
   */
  taskSupport: rt(["required", "optional", "forbidden"]).optional()
}), Tu = G({
  ...ir.shape,
  ...Mr.shape,
  /**
   * A human-readable description of the tool.
   */
  description: x().optional(),
  /**
   * A JSON Schema 2020-12 object defining the expected parameters for the tool.
   * Must have type: 'object' at the root level per MCP spec.
   */
  inputSchema: G({
    type: ee("object"),
    properties: Te(x(), De).optional(),
    required: he(x()).optional()
  }).catchall(Oe()),
  /**
   * An optional JSON Schema 2020-12 object defining the structure of the tool's output
   * returned in the structuredContent field of a CallToolResult.
   * Must have type: 'object' at the root level per MCP spec.
   */
  outputSchema: G({
    type: ee("object"),
    properties: Te(x(), De).optional(),
    required: he(x()).optional()
  }).catchall(Oe()).optional(),
  /**
   * Optional additional tool information.
   */
  annotations: dm.optional(),
  /**
   * Execution-related properties for this tool.
   */
  execution: hm.optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), ei = Dr.extend({
  method: ee("tools/list")
}), fm = Ur.extend({
  tools: he(Tu)
}), Qi = Ve.extend({
  /**
   * A list of content objects that represent the result of the tool call.
   *
   * If the Tool does not define an outputSchema, this field MUST be present in the result.
   * For backwards compatibility, this field is always present, but it may be empty.
   */
  content: he(Bi).default([]),
  /**
   * An object containing structured tool output.
   *
   * If the Tool defines an outputSchema, this field MUST be present in the result, and contain a JSON object that matches the schema.
   */
  structuredContent: Te(x(), Oe()).optional(),
  /**
   * Whether the tool call ended in an error.
   *
   * If not set, this is assumed to be false (the call was successful).
   *
   * Any errors that originate from the tool SHOULD be reported inside the result
   * object, with `isError` set to true, _not_ as an MCP protocol-level error
   * response. Otherwise, the LLM would not be able to see that an error occurred
   * and self-correct.
   *
   * However, any errors in _finding_ the tool, an error indicating that the
   * server does not support tool calls, or any other exceptional conditions,
   * should be reported as an MCP error response.
   */
  isError: Fe().optional()
});
Qi.or(Ve.extend({
  toolResult: Oe()
}));
const pm = qr.extend({
  /**
   * The name of the tool to call.
   */
  name: x(),
  /**
   * Arguments to pass to the tool.
   */
  arguments: Te(x(), Oe()).optional()
}), Zn = He.extend({
  method: ee("tools/call"),
  params: pm
}), mm = ot.extend({
  method: ee("notifications/tools/list_changed"),
  params: at.optional()
});
G({
  /**
   * If true, the list will be refreshed automatically when a list changed notification is received.
   * The callback will be called with the updated list.
   *
   * If false, the callback will be called with null items, allowing manual refresh.
   *
   * @default true
   */
  autoRefresh: Fe().default(!0),
  /**
   * Debounce time in milliseconds for list changed notification processing.
   *
   * Multiple notifications received within this timeframe will only trigger one refresh.
   * Set to 0 to disable debouncing.
   *
   * @default 300
   */
  debounceMs: Se().int().nonnegative().default(300)
});
const Fn = rt(["debug", "info", "notice", "warning", "error", "critical", "alert", "emergency"]), gm = nt.extend({
  /**
   * The level of logging that the client wants to receive from the server. The server should send all logs at this level and higher (i.e., more severe) to the client as notifications/logging/message.
   */
  level: Fn
}), Ru = He.extend({
  method: ee("logging/setLevel"),
  params: gm
}), _m = at.extend({
  /**
   * The severity of this log message.
   */
  level: Fn,
  /**
   * An optional name of the logger issuing this message.
   */
  logger: x().optional(),
  /**
   * The data to be logged, such as a string message or an object. Any JSON serializable type is allowed here.
   */
  data: Oe()
}), ym = ot.extend({
  method: ee("notifications/message"),
  params: _m
}), vm = G({
  /**
   * A hint for a model name.
   */
  name: x().optional()
}), wm = G({
  /**
   * Optional hints to use for model selection.
   */
  hints: he(vm).optional(),
  /**
   * How much to prioritize cost when selecting a model.
   */
  costPriority: Se().min(0).max(1).optional(),
  /**
   * How much to prioritize sampling speed (latency) when selecting a model.
   */
  speedPriority: Se().min(0).max(1).optional(),
  /**
   * How much to prioritize intelligence and capabilities when selecting a model.
   */
  intelligencePriority: Se().min(0).max(1).optional()
}), bm = G({
  /**
   * Controls when tools are used:
   * - "auto": Model decides whether to use tools (default)
   * - "required": Model MUST use at least one tool before completing
   * - "none": Model MUST NOT use any tools
   */
  mode: rt(["auto", "required", "none"]).optional()
}), Sm = G({
  type: ee("tool_result"),
  toolUseId: x().describe("The unique identifier for the corresponding tool call."),
  content: he(Bi).default([]),
  structuredContent: G({}).loose().optional(),
  isError: Fe().optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), km = cu("type", [Gi, Wi, Ji]), Hn = cu("type", [
  Gi,
  Wi,
  Ji,
  im,
  Sm
]), $m = G({
  role: Zr,
  content: Ie([Hn, he(Hn)]),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), Em = qr.extend({
  messages: he($m),
  /**
   * The server's preferences for which model to select. The client MAY modify or omit this request.
   */
  modelPreferences: wm.optional(),
  /**
   * An optional system prompt the server wants to use for sampling. The client MAY modify or omit this prompt.
   */
  systemPrompt: x().optional(),
  /**
   * A request to include context from one or more MCP servers (including the caller), to be attached to the prompt.
   * The client MAY ignore this request.
   *
   * Default is "none". Values "thisServer" and "allServers" are soft-deprecated. Servers SHOULD only use these values if the client
   * declares ClientCapabilities.sampling.context. These values may be removed in future spec releases.
   */
  includeContext: rt(["none", "thisServer", "allServers"]).optional(),
  temperature: Se().optional(),
  /**
   * The requested maximum number of tokens to sample (to prevent runaway completions).
   *
   * The client MAY choose to sample fewer tokens than the requested maximum.
   */
  maxTokens: Se().int(),
  stopSequences: he(x()).optional(),
  /**
   * Optional metadata to pass through to the LLM provider. The format of this metadata is provider-specific.
   */
  metadata: De.optional(),
  /**
   * Tools that the model may use during generation.
   * The client MUST return an error if this field is provided but ClientCapabilities.sampling.tools is not declared.
   */
  tools: he(Tu).optional(),
  /**
   * Controls how the model uses tools.
   * The client MUST return an error if this field is provided but ClientCapabilities.sampling.tools is not declared.
   * Default is `{ mode: "auto" }`.
   */
  toolChoice: bm.optional()
}), Tm = He.extend({
  method: ee("sampling/createMessage"),
  params: Em
}), Yi = Ve.extend({
  /**
   * The name of the model that generated the message.
   */
  model: x(),
  /**
   * The reason why sampling stopped, if known.
   *
   * Standard values:
   * - "endTurn": Natural end of the assistant's turn
   * - "stopSequence": A stop sequence was encountered
   * - "maxTokens": Maximum token limit was reached
   *
   * This field is an open string to allow for provider-specific stop reasons.
   */
  stopReason: Pe(rt(["endTurn", "stopSequence", "maxTokens"]).or(x())),
  role: Zr,
  /**
   * Response content. Single content block (text, image, or audio).
   */
  content: km
}), Iu = Ve.extend({
  /**
   * The name of the model that generated the message.
   */
  model: x(),
  /**
   * The reason why sampling stopped, if known.
   *
   * Standard values:
   * - "endTurn": Natural end of the assistant's turn
   * - "stopSequence": A stop sequence was encountered
   * - "maxTokens": Maximum token limit was reached
   * - "toolUse": The model wants to use one or more tools
   *
   * This field is an open string to allow for provider-specific stop reasons.
   */
  stopReason: Pe(rt(["endTurn", "stopSequence", "maxTokens", "toolUse"]).or(x())),
  role: Zr,
  /**
   * Response content. May be a single block or array. May include ToolUseContent if stopReason is "toolUse".
   */
  content: Ie([Hn, he(Hn)])
}), Rm = G({
  type: ee("boolean"),
  title: x().optional(),
  description: x().optional(),
  default: Fe().optional()
}), Im = G({
  type: ee("string"),
  title: x().optional(),
  description: x().optional(),
  minLength: Se().optional(),
  maxLength: Se().optional(),
  format: rt(["email", "uri", "date", "date-time"]).optional(),
  default: x().optional()
}), Pm = G({
  type: rt(["number", "integer"]),
  title: x().optional(),
  description: x().optional(),
  minimum: Se().optional(),
  maximum: Se().optional(),
  default: Se().optional()
}), Om = G({
  type: ee("string"),
  title: x().optional(),
  description: x().optional(),
  enum: he(x()),
  default: x().optional()
}), Cm = G({
  type: ee("string"),
  title: x().optional(),
  description: x().optional(),
  oneOf: he(G({
    const: x(),
    title: x()
  })),
  default: x().optional()
}), Am = G({
  type: ee("string"),
  title: x().optional(),
  description: x().optional(),
  enum: he(x()),
  enumNames: he(x()).optional(),
  default: x().optional()
}), Nm = Ie([Om, Cm]), xm = G({
  type: ee("array"),
  title: x().optional(),
  description: x().optional(),
  minItems: Se().optional(),
  maxItems: Se().optional(),
  items: G({
    type: ee("string"),
    enum: he(x())
  }),
  default: he(x()).optional()
}), jm = G({
  type: ee("array"),
  title: x().optional(),
  description: x().optional(),
  minItems: Se().optional(),
  maxItems: Se().optional(),
  items: G({
    anyOf: he(G({
      const: x(),
      title: x()
    }))
  }),
  default: he(x()).optional()
}), zm = Ie([xm, jm]), qm = Ie([Am, Nm, zm]), Mm = Ie([qm, Rm, Im, Pm]), Dm = qr.extend({
  /**
   * The elicitation mode.
   *
   * Optional for backward compatibility. Clients MUST treat missing mode as "form".
   */
  mode: ee("form").optional(),
  /**
   * The message to present to the user describing what information is being requested.
   */
  message: x(),
  /**
   * A restricted subset of JSON Schema.
   * Only top-level properties are allowed, without nesting.
   */
  requestedSchema: G({
    type: ee("object"),
    properties: Te(x(), Mm),
    required: he(x()).optional()
  })
}), Um = qr.extend({
  /**
   * The elicitation mode.
   */
  mode: ee("url"),
  /**
   * The message to present to the user explaining why the interaction is needed.
   */
  message: x(),
  /**
   * The ID of the elicitation, which must be unique within the context of the server.
   * The client MUST treat this ID as an opaque value.
   */
  elicitationId: x(),
  /**
   * The URL that the user should navigate to.
   */
  url: x().url()
}), Lm = Ie([Dm, Um]), Zm = He.extend({
  method: ee("elicitation/create"),
  params: Lm
}), Fm = at.extend({
  /**
   * The ID of the elicitation that completed.
   */
  elicitationId: x()
}), Hm = ot.extend({
  method: ee("notifications/elicitation/complete"),
  params: Fm
}), Vn = Ve.extend({
  /**
   * The user action in response to the elicitation.
   * - "accept": User submitted the form/confirmed the action
   * - "decline": User explicitly decline the action
   * - "cancel": User dismissed without making an explicit choice
   */
  action: rt(["accept", "decline", "cancel"]),
  /**
   * The submitted form data, only present when action is "accept".
   * Contains values matching the requested schema.
   * Per MCP spec, content is "typically omitted" for decline/cancel actions.
   * We normalize null to undefined for leniency while maintaining type compatibility.
   */
  content: fu((t) => t === null ? void 0 : t, Te(x(), Ie([x(), Se(), Fe(), he(x())])).optional())
}), Vm = G({
  type: ee("ref/resource"),
  /**
   * The URI or URI template of the resource.
   */
  uri: x()
}), Km = G({
  type: ee("ref/prompt"),
  /**
   * The name of the prompt or prompt template
   */
  name: x()
}), Gm = nt.extend({
  ref: Ie([Km, Vm]),
  /**
   * The argument's information
   */
  argument: G({
    /**
     * The name of the argument
     */
    name: x(),
    /**
     * The value of the argument to use for completion matching.
     */
    value: x()
  }),
  context: G({
    /**
     * Previously-resolved variables in a URI template or prompt.
     */
    arguments: Te(x(), x()).optional()
  }).optional()
}), ti = He.extend({
  method: ee("completion/complete"),
  params: Gm
});
function Wm(t) {
  if (t.params.ref.type !== "ref/prompt")
    throw new TypeError(`Expected CompleteRequestPrompt, but got ${t.params.ref.type}`);
}
function Jm(t) {
  if (t.params.ref.type !== "ref/resource")
    throw new TypeError(`Expected CompleteRequestResourceTemplate, but got ${t.params.ref.type}`);
}
const Bm = Ve.extend({
  completion: Ye({
    /**
     * An array of completion values. Must not exceed 100 items.
     */
    values: he(x()).max(100),
    /**
     * The total number of completion options available. This can exceed the number of values actually sent in the response.
     */
    total: Pe(Se().int()),
    /**
     * Indicates whether there are additional completion options beyond those provided in the current response, even if the exact total is unknown.
     */
    hasMore: Pe(Fe())
  })
}), Qm = G({
  /**
   * The URI identifying the root. This *must* start with file:// for now.
   */
  uri: x().startsWith("file://"),
  /**
   * An optional name for the root.
   */
  name: x().optional(),
  /**
   * See [MCP specification](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/47339c03c143bb4ec01a26e721a1b8fe66634ebe/docs/specification/draft/basic/index.mdx#general-fields)
   * for notes on _meta usage.
   */
  _meta: Te(x(), Oe()).optional()
}), Ym = He.extend({
  method: ee("roots/list"),
  params: nt.optional()
}), Pu = Ve.extend({
  roots: he(Qm)
}), Xm = ot.extend({
  method: ee("notifications/roots/list_changed"),
  params: at.optional()
});
Ie([
  qi,
  wu,
  ti,
  Ru,
  Xs,
  Ys,
  Js,
  Bs,
  Qs,
  Bp,
  Yp,
  Zn,
  ei,
  Di,
  Li,
  Zi,
  Hi
]);
Ie([
  zi,
  Mi,
  bu,
  Xm,
  Ln
]);
Ie([
  ji,
  Yi,
  Iu,
  Vn,
  Pu,
  Ui,
  Fi,
  ds
]);
Ie([
  qi,
  Tm,
  Zm,
  Ym,
  Di,
  Li,
  Zi,
  Hi
]);
Ie([
  zi,
  Mi,
  ym,
  em,
  Wp,
  mm,
  lm,
  Ln,
  Hm
]);
Ie([
  ji,
  zp,
  Bm,
  um,
  nm,
  Hp,
  Vp,
  Gp,
  Qi,
  fm,
  Ui,
  Fi,
  ds
]);
class X extends Error {
  constructor(e, r, n) {
    super(`MCP error ${e}: ${r}`), this.code = e, this.data = n, this.name = "McpError";
  }
  /**
   * Factory method to create the appropriate error type based on the error code and data
   */
  static fromError(e, r, n) {
    if (e === re.UrlElicitationRequired && n) {
      const s = n;
      if (s.elicitations)
        return new eg(s.elicitations, r);
    }
    return new X(e, r, n);
  }
}
class eg extends X {
  constructor(e, r = `URL elicitation${e.length > 1 ? "s" : ""} required`) {
    super(re.UrlElicitationRequired, r, {
      elicitations: e
    });
  }
  get elicitations() {
    var e;
    return ((e = this.data) == null ? void 0 : e.elicitations) ?? [];
  }
}
const Pa = { none: 0, error: 1, warn: 2, info: 3, debug: 4 }, tg = { error: "error", warn: "warn", info: "info", log: "info", debug: "debug" }, rg = (t, e) => Pa[t] <= Pa[e], ri = (t) => typeof t == "string" ? t : JSON.stringify(t), ng = (t, e) => `${ri(t)} > ${ri(e)}`, sg = (t, e) => {
  let r = `[${ri(t)}]`;
  return typeof window < "u" ? { text: `%c${r}`, style: `color: ${e.color || "#00bcd4"}; font-weight: bold;` } : { text: r };
}, mr = (t, e, r, n) => (...s) => {
  if (!rg(tg[t], n())) return;
  if (!e) return void console[t](...s);
  let { text: i, style: a } = sg(e, r);
  a ? console[t](i, a, ...s) : console[t](i, ...s);
}, Ou = (t, e) => {
  let r = e.logLevel ?? "debug", n = () => r;
  return { log: mr("log", t, e, n), info: mr("info", t, e, n), warn: mr("warn", t, e, n), error: mr("error", t, e, n), debug: mr("debug", t, e, n), setLogLevel: (s) => {
    r = s;
  }, extend: (s) => Ou(t ? ng(t, s) : s, { ...e, logLevel: r }) };
}, Xi = (t, e) => Ou(t, { color: "#00bcd4", logLevel: "debug", ...e }), ig = Xi("angie-sdk", { color: "#00BCD4", logLevel: "error" }), St = (t) => ig.extend(t), Ss = St("iframe-utils");
let Wr = null;
const Oa = () => (Wr && document.contains(Wr) || (Wr = document.querySelector('iframe[src*="angie/"]')), Wr), ni = (t, e) => {
  Ss.log("postMessageToAngieIframe", t, e);
  const r = Oa();
  if (!(r != null && r.contentWindow)) return !1;
  const n = (() => {
    const s = Oa();
    if (!s) return null;
    try {
      return new URL(s.src).origin;
    } catch (i) {
      return Ss.error("Error parsing iframe URL:", i), null;
    }
  })();
  return n ? (r.contentWindow.postMessage(t, n), !0) : (Ss.error("Could not determine target origin for Angie iframe"), !1);
};
var Ca, Kn, Aa, rr, xe, Gn, nr;
(function(t) {
  t.POST_MESSAGE = "postMessage";
})(Ca || (Ca = {})), (function(t) {
  t.POST_MESSAGE = "postMessage";
})(Kn || (Kn = {})), (function(t) {
  t.STREAMABLE_HTTP = "streamableHttp", t.SSE = "sse";
})(Aa || (Aa = {})), (function(t) {
  t.LOCAL = "local", t.REMOTE = "remote";
})(rr || (rr = {})), (function(t) {
  t.SDK_ANGIE_READY_PING = "sdk-angie-ready-ping", t.SDK_ANGIE_REFRESH_PING = "sdk-angie-refresh-ping", t.SDK_ANGIE_ALL_SERVERS_REGISTERED = "sdk-angie-all-servers-registered", t.SDK_REQUEST_CLIENT_CREATION = "sdk-request-client-creation", t.SDK_REQUEST_INIT_SERVER = "sdk-request-init-server", t.SDK_TRIGGER_ANGIE = "sdk-trigger-angie", t.SDK_TRIGGER_ANGIE_RESPONSE = "sdk-trigger-angie-response", t.ANGIE_SIDEBAR_RESIZED = "angie-sidebar-resized", t.ANGIE_SIDEBAR_TOGGLED = "angie-sidebar-toggled", t.ANGIE_CHAT_TOGGLE = "angie-chat-toggle", t.ANGIE_STUDIO_TOGGLE = "angie-studio-toggle", t.ANGIE_NAVIGATE_TO_URL = "angie/navigate-to-url", t.ANGIE_PAGE_RELOAD = "angie/page-reload", t.ANGIE_DISABLE_NAVIGATION_PREVENTION = "angie/disable-navigation-prevention", t.ANGIE_NAVIGATE_AFTER_RESPONSE = "angie/navigate-after-response";
})(xe || (xe = {})), (function(t) {
  t.SET = "ANGIE_SET_LOCALSTORAGE", t.GET = "ANGIE_GET_LOCALSTORAGE";
})(Gn || (Gn = {})), (function(t) {
  t.RESET_HASH = "reset-hash", t.HOST_READY = "host/ready", t.ANGIE_LOADED = "angie/loaded", t.ANGIE_READY = "angie/ready";
})(nr || (nr = {}));
const ag = St("angie-detector");
class og {
  constructor() {
    ve(this, "isAngieReady", !1);
    ve(this, "readyPromise");
    ve(this, "readyResolve");
    if (this.readyPromise = new Promise((n) => {
      this.readyResolve = n;
    }), typeof window > "u") return;
    let e = 0;
    const r = () => {
      if (this.isAngieReady || e >= 500) return void (!this.isAngieReady && e >= 500 && this.handleDetectionTimeout());
      const n = new MessageChannel();
      n.port1.onmessage = (i) => {
        this.handleAngieReady(i.data), n.port1.close(), n.port2.close();
      };
      const s = { type: xe.SDK_ANGIE_READY_PING, timestamp: Date.now() };
      window.postMessage(s, window.location.origin, [n.port2]), e++, setTimeout(r, 500);
    };
    r();
  }
  handleAngieReady(e) {
    this.isAngieReady = !0;
    const r = { isReady: !0, version: e.version, capabilities: e.capabilities };
    this.readyResolve && this.readyResolve(r);
  }
  handleDetectionTimeout() {
    this.readyResolve && this.readyResolve({ isReady: !1 }), ag.warn("Detection timeout - Angie may not be available");
  }
  isReady() {
    return this.isAngieReady;
  }
  async waitForReady() {
    return this.readyPromise;
  }
}
class cg {
  constructor(e) {
    ve(this, "sessionId");
    ve(this, "onmessage");
    ve(this, "onerror");
    ve(this, "onclose");
    ve(this, "_port");
    ve(this, "_started", !1);
    ve(this, "_closed", !1);
    if (!e) throw new Error("MessagePort is required");
    this._port = e, this._port.onmessage = (r) => {
      var n, s;
      try {
        const i = Tp.parse(r.data);
        (n = this.onmessage) == null || n.call(this, i);
      } catch (i) {
        const a = new Error(`Failed to parse message: ${i}`);
        (s = this.onerror) == null || s.call(this, a);
      }
    }, this._port.onmessageerror = (r) => {
      var s;
      const n = new Error(`MessagePort error: ${JSON.stringify(r)}`);
      (s = this.onerror) == null || s.call(this, n);
    };
  }
  async start() {
    if (this._started) throw new Error("BrowserContextTransport already started! If using Client or Server class, note that connect() calls start() automatically.");
    if (this._closed) throw new Error("Cannot start a closed BrowserContextTransport");
    this._started = !0, this._port.start();
  }
  async send(e) {
    if (this._closed) throw new Error("Cannot send on a closed BrowserContextTransport");
    return new Promise((r, n) => {
      var s;
      try {
        this._port.postMessage(e), r();
      } catch (i) {
        const a = i instanceof Error ? i : new Error(String(i));
        (s = this.onerror) == null || s.call(this, a), n(a);
      }
    });
  }
  async close() {
    var e;
    this._closed || (this._closed = !0, this._port.close(), (e = this.onclose) == null || e.call(this));
  }
}
class ug {
  async requestClientCreation(e) {
    const { config: r } = e, n = { serverId: e.id, serverName: r.name, serverTitle: r.title, serverVersion: r.version, description: r.description, transport: r.transport || Kn.POST_MESSAGE, capabilities: r.capabilities, instanceId: e.instanceId };
    return "type" in r && r.type === "remote" && (n.remote = { url: r.url }), new Promise((s, i) => {
      const a = new MessageChannel(), o = setTimeout(() => {
        i(new Error("Client creation request timed out after 15000ms"));
      }, 15e3);
      a.port1.onmessage = (u) => {
        clearTimeout(o), s(u.data);
      };
      const c = { type: xe.SDK_REQUEST_CLIENT_CREATION, payload: n, timestamp: Date.now() };
      window.postMessage(c, window.location.origin, [a.port2]);
    });
  }
}
const Cu = "angie-sidebar-container", Ne = { open: !1, iframe: null, iframeUrlObject: null, containerId: Cu };
class kr extends Error {
}
kr.prototype.name = "InvalidTokenError";
var _t, yt, ks, lg = { debug: () => {
}, info: () => {
}, warn: () => {
}, error: () => {
} }, Jt = ((t) => (t[t.NONE = 0] = "NONE", t[t.ERROR = 1] = "ERROR", t[t.WARN = 2] = "WARN", t[t.INFO = 3] = "INFO", t[t.DEBUG = 4] = "DEBUG", t))(Jt || {});
(ks = Jt || (Jt = {})).reset = function() {
  _t = 3, yt = lg;
}, ks.setLevel = function(t) {
  if (!(0 <= t && t <= 4)) throw new Error("Invalid log level");
  _t = t;
}, ks.setLogger = function(t) {
  yt = t;
};
var ue = class mt {
  constructor(e) {
    this._name = e;
  }
  debug(...e) {
    _t >= 4 && yt.debug(mt._format(this._name, this._method), ...e);
  }
  info(...e) {
    _t >= 3 && yt.info(mt._format(this._name, this._method), ...e);
  }
  warn(...e) {
    _t >= 2 && yt.warn(mt._format(this._name, this._method), ...e);
  }
  error(...e) {
    _t >= 1 && yt.error(mt._format(this._name, this._method), ...e);
  }
  throw(e) {
    throw this.error(e), e;
  }
  create(e) {
    const r = Object.create(this);
    return r._method = e, r.debug("begin"), r;
  }
  static createStatic(e, r) {
    const n = new mt(`${e}.${r}`);
    return n.debug("begin"), n;
  }
  static _format(e, r) {
    const n = `[${e}]`;
    return r ? `${n} ${r}:` : n;
  }
  static debug(e, ...r) {
    _t >= 4 && yt.debug(mt._format(e), ...r);
  }
  static info(e, ...r) {
    _t >= 3 && yt.info(mt._format(e), ...r);
  }
  static warn(e, ...r) {
    _t >= 2 && yt.warn(mt._format(e), ...r);
  }
  static error(e, ...r) {
    _t >= 1 && yt.error(mt._format(e), ...r);
  }
};
Jt.reset();
var Or = class {
  static decode(t) {
    try {
      return (function(e, r) {
        if (typeof e != "string") throw new kr("Invalid token specified: must be a string");
        r || (r = {});
        const n = r.header === !0 ? 0 : 1, s = e.split(".")[n];
        if (typeof s != "string") throw new kr(`Invalid token specified: missing part #${n + 1}`);
        let i;
        try {
          i = (function(a) {
            let o = a.replace(/-/g, "+").replace(/_/g, "/");
            switch (o.length % 4) {
              case 0:
                break;
              case 2:
                o += "==";
                break;
              case 3:
                o += "=";
                break;
              default:
                throw new Error("base64 string is not of the correct length");
            }
            try {
              return (function(c) {
                return decodeURIComponent(atob(c).replace(/(.)/g, (u, l) => {
                  let p = l.charCodeAt(0).toString(16).toUpperCase();
                  return p.length < 2 && (p = "0" + p), "%" + p;
                }));
              })(o);
            } catch {
              return atob(o);
            }
          })(s);
        } catch (a) {
          throw new kr(`Invalid token specified: invalid base64 for part #${n + 1} (${a.message})`);
        }
        try {
          return JSON.parse(i);
        } catch (a) {
          throw new kr(`Invalid token specified: invalid json for part #${n + 1} (${a.message})`);
        }
      })(t);
    } catch (e) {
      throw ue.error("JwtUtils.decode", e), e;
    }
  }
  static async generateSignedJwt(t, e, r) {
    const n = `${qe.encodeBase64Url(new TextEncoder().encode(JSON.stringify(t)))}.${qe.encodeBase64Url(new TextEncoder().encode(JSON.stringify(e)))}`, s = await window.crypto.subtle.sign({ name: "ECDSA", hash: { name: "SHA-256" } }, r, new TextEncoder().encode(n));
    return `${n}.${qe.encodeBase64Url(new Uint8Array(s))}`;
  }
  static async generateSignedJwtWithHmac(t, e, r) {
    const n = `${qe.encodeBase64Url(new TextEncoder().encode(JSON.stringify(t)))}.${qe.encodeBase64Url(new TextEncoder().encode(JSON.stringify(e)))}`, s = await window.crypto.subtle.sign("HMAC", r, new TextEncoder().encode(n));
    return `${n}.${qe.encodeBase64Url(new Uint8Array(s))}`;
  }
}, si = (t) => btoa([...new Uint8Array(t)].map((e) => String.fromCharCode(e)).join("")), Au = class ft {
  static _randomWord() {
    const e = new Uint32Array(1);
    return crypto.getRandomValues(e), e[0];
  }
  static generateUUIDv4() {
    return "10000000-1000-4000-8000-100000000000".replace(/[018]/g, (r) => (+r ^ ft._randomWord() & 15 >> +r / 4).toString(16)).replace(/-/g, "");
  }
  static generateCodeVerifier() {
    return ft.generateUUIDv4() + ft.generateUUIDv4() + ft.generateUUIDv4();
  }
  static async generateCodeChallenge(e) {
    if (!crypto.subtle) throw new Error("Crypto.subtle is available only in secure contexts (HTTPS).");
    try {
      const r = new TextEncoder().encode(e), n = await crypto.subtle.digest("SHA-256", r);
      return si(n).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    } catch (r) {
      throw ue.error("CryptoUtils.generateCodeChallenge", r), r;
    }
  }
  static generateBasicAuth(e, r) {
    const n = new TextEncoder().encode([e, r].join(":"));
    return si(n);
  }
  static async hash(e, r) {
    const n = new TextEncoder().encode(r), s = await crypto.subtle.digest(e, n);
    return new Uint8Array(s);
  }
  static async customCalculateJwkThumbprint(e) {
    let r;
    switch (e.kty) {
      case "RSA":
        r = { e: e.e, kty: e.kty, n: e.n };
        break;
      case "EC":
        r = { crv: e.crv, kty: e.kty, x: e.x, y: e.y };
        break;
      case "OKP":
        r = { crv: e.crv, kty: e.kty, x: e.x };
        break;
      case "oct":
        r = { crv: e.k, kty: e.kty };
        break;
      default:
        throw new Error("Unknown jwk type");
    }
    const n = await ft.hash("SHA-256", JSON.stringify(r));
    return ft.encodeBase64Url(n);
  }
  static async generateDPoPProof({ url: e, accessToken: r, httpMethod: n, keyPair: s, nonce: i }) {
    let a, o;
    const c = { jti: window.crypto.randomUUID(), htm: n ?? "GET", htu: e, iat: Math.floor(Date.now() / 1e3) };
    r && (a = await ft.hash("SHA-256", r), o = ft.encodeBase64Url(a), c.ath = o), i && (c.nonce = i);
    try {
      const u = await crypto.subtle.exportKey("jwk", s.publicKey), l = { alg: "ES256", typ: "dpop+jwt", jwk: { crv: u.crv, kty: u.kty, x: u.x, y: u.y } };
      return await Or.generateSignedJwt(l, c, s.privateKey);
    } catch (u) {
      throw u instanceof TypeError ? new Error(`Error exporting dpop public key: ${u.message}`) : u;
    }
  }
  static async generateDPoPJkt(e) {
    try {
      const r = await crypto.subtle.exportKey("jwk", e.publicKey);
      return await ft.customCalculateJwkThumbprint(r);
    } catch (r) {
      throw r instanceof TypeError ? new Error(`Could not retrieve dpop keys from storage: ${r.message}`) : r;
    }
  }
  static async generateDPoPKeys() {
    return await window.crypto.subtle.generateKey({ name: "ECDSA", namedCurve: "P-256" }, !1, ["sign", "verify"]);
  }
  static async generateClientAssertionJwt(e, r, n, s = "HS256") {
    const i = Math.floor(Date.now() / 1e3), a = { alg: s, typ: "JWT" }, o = { iss: e, sub: e, aud: n, jti: ft.generateUUIDv4(), exp: i + 300, iat: i }, c = { HS256: "SHA-256", HS384: "SHA-384", HS512: "SHA-512" }[s];
    if (!c) throw new Error(`Unsupported algorithm: ${s}. Supported algorithms are: HS256, HS384, HS512`);
    const u = new TextEncoder(), l = await crypto.subtle.importKey("raw", u.encode(r), { name: "HMAC", hash: c }, !1, ["sign"]);
    return await Or.generateSignedJwtWithHmac(a, o, l);
  }
};
Au.encodeBase64Url = (t) => si(t).replace(/=/g, "").replace(/\+/g, "-").replace(/\//g, "_");
var qe = Au, Nt = class {
  constructor(t) {
    this._name = t, this._callbacks = [], this._logger = new ue(`Event('${this._name}')`);
  }
  addHandler(t) {
    return this._callbacks.push(t), () => this.removeHandler(t);
  }
  removeHandler(t) {
    const e = this._callbacks.lastIndexOf(t);
    e >= 0 && this._callbacks.splice(e, 1);
  }
  async raise(...t) {
    this._logger.debug("raise:", ...t);
    for (const e of this._callbacks) await e(...t);
  }
}, Na = class {
  static center({ ...t }) {
    var e;
    return t.width == null && (t.width = (e = [800, 720, 600, 480].find((r) => r <= window.outerWidth / 1.618)) != null ? e : 360), t.left != null || (t.left = Math.max(0, Math.round(window.screenX + (window.outerWidth - t.width) / 2))), t.height != null && (t.top != null || (t.top = Math.max(0, Math.round(window.screenY + (window.outerHeight - t.height) / 2)))), t;
  }
  static serialize(t) {
    return Object.entries(t).filter(([, e]) => e != null).map(([e, r]) => `${e}=${typeof r != "boolean" ? r : r ? "yes" : "no"}`).join(",");
  }
}, Pt = class zn extends Nt {
  constructor() {
    super(...arguments), this._logger = new ue(`Timer('${this._name}')`), this._timerHandle = null, this._expiration = 0, this._callback = () => {
      const e = this._expiration - zn.getEpochTime();
      this._logger.debug("timer completes in", e), this._expiration <= zn.getEpochTime() && (this.cancel(), super.raise());
    };
  }
  static getEpochTime() {
    return Math.floor(Date.now() / 1e3);
  }
  init(e) {
    const r = this._logger.create("init");
    e = Math.max(Math.floor(e), 1);
    const n = zn.getEpochTime() + e;
    if (this.expiration === n && this._timerHandle) return void r.debug("skipping since already initialized for expiration at", this.expiration);
    this.cancel(), r.debug("using duration", e), this._expiration = n;
    const s = Math.min(e, 5);
    this._timerHandle = setInterval(this._callback, 1e3 * s);
  }
  get expiration() {
    return this._expiration;
  }
  cancel() {
    this._logger.create("cancel"), this._timerHandle && (clearInterval(this._timerHandle), this._timerHandle = null);
  }
}, ii = class {
  static readParams(t, e = "query") {
    if (!t) throw new TypeError("Invalid URL");
    const r = new URL(t, "http://127.0.0.1")[e === "fragment" ? "hash" : "search"];
    return new URLSearchParams(r.slice(1));
  }
}, ar = ";", Bt = class extends Error {
  constructor(t, e) {
    var r, n, s;
    if (super(t.error_description || t.error || ""), this.form = e, this.name = "ErrorResponse", !t.error) throw ue.error("ErrorResponse", "No error passed"), new Error("No error passed");
    this.error = t.error, this.error_description = (r = t.error_description) != null ? r : null, this.error_uri = (n = t.error_uri) != null ? n : null, this.state = t.userState, this.session_state = (s = t.session_state) != null ? s : null, this.url_state = t.url_state;
  }
}, ea = class extends Error {
  constructor(t) {
    super(t), this.name = "ErrorTimeout";
  }
}, dg = class {
  constructor(t) {
    this._logger = new ue("AccessTokenEvents"), this._expiringTimer = new Pt("Access token expiring"), this._expiredTimer = new Pt("Access token expired"), this._expiringNotificationTimeInSeconds = t.expiringNotificationTimeInSeconds;
  }
  async load(t) {
    const e = this._logger.create("load");
    if (t.access_token && t.expires_in !== void 0) {
      const r = t.expires_in;
      if (e.debug("access token present, remaining duration:", r), r > 0) {
        let s = r - this._expiringNotificationTimeInSeconds;
        s <= 0 && (s = 1), e.debug("registering expiring timer, raising in", s, "seconds"), this._expiringTimer.init(s);
      } else e.debug("canceling existing expiring timer because we're past expiration."), this._expiringTimer.cancel();
      const n = r + 1;
      e.debug("registering expired timer, raising in", n, "seconds"), this._expiredTimer.init(n);
    } else this._expiringTimer.cancel(), this._expiredTimer.cancel();
  }
  async unload() {
    this._logger.debug("unload: canceling existing access token timers"), this._expiringTimer.cancel(), this._expiredTimer.cancel();
  }
  addAccessTokenExpiring(t) {
    return this._expiringTimer.addHandler(t);
  }
  removeAccessTokenExpiring(t) {
    this._expiringTimer.removeHandler(t);
  }
  addAccessTokenExpired(t) {
    return this._expiredTimer.addHandler(t);
  }
  removeAccessTokenExpired(t) {
    this._expiredTimer.removeHandler(t);
  }
}, hg = class {
  constructor(t, e, r, n, s) {
    this._callback = t, this._client_id = e, this._intervalInSeconds = n, this._stopOnError = s, this._logger = new ue("CheckSessionIFrame"), this._timer = null, this._session_state = null, this._message = (a) => {
      a.origin === this._frame_origin && a.source === this._frame.contentWindow && (a.data === "error" ? (this._logger.error("error message from check session op iframe"), this._stopOnError && this.stop()) : a.data === "changed" ? (this._logger.debug("changed message from check session op iframe"), this.stop(), this._callback()) : this._logger.debug(a.data + " message from check session op iframe"));
    };
    const i = new URL(r);
    this._frame_origin = i.origin, this._frame = window.document.createElement("iframe"), this._frame.style.visibility = "hidden", this._frame.style.position = "fixed", this._frame.style.left = "-1000px", this._frame.style.top = "0", this._frame.width = "0", this._frame.height = "0", this._frame.src = i.href;
  }
  load() {
    return new Promise((t) => {
      this._frame.onload = () => {
        t();
      }, window.document.body.appendChild(this._frame), window.addEventListener("message", this._message, !1);
    });
  }
  start(t) {
    if (this._session_state === t) return;
    this._logger.create("start"), this.stop(), this._session_state = t;
    const e = () => {
      this._frame.contentWindow && this._session_state && this._frame.contentWindow.postMessage(this._client_id + " " + this._session_state, this._frame_origin);
    };
    e(), this._timer = setInterval(e, 1e3 * this._intervalInSeconds);
  }
  stop() {
    this._logger.create("stop"), this._session_state = null, this._timer && (clearInterval(this._timer), this._timer = null);
  }
}, Nu = class {
  constructor() {
    this._logger = new ue("InMemoryWebStorage"), this._data = {};
  }
  clear() {
    this._logger.create("clear"), this._data = {};
  }
  getItem(t) {
    return this._logger.create(`getItem('${t}')`), this._data[t];
  }
  setItem(t, e) {
    this._logger.create(`setItem('${t}')`), this._data[t] = e;
  }
  removeItem(t) {
    this._logger.create(`removeItem('${t}')`), delete this._data[t];
  }
  get length() {
    return Object.getOwnPropertyNames(this._data).length;
  }
  key(t) {
    return Object.getOwnPropertyNames(this._data)[t];
  }
}, ai = class extends Error {
  constructor(t, e) {
    super(e), this.name = "ErrorDPoPNonce", this.nonce = t;
  }
}, ta = class {
  constructor(t = [], e = null, r = {}) {
    this._jwtHandler = e, this._extraHeaders = r, this._logger = new ue("JsonService"), this._contentTypes = [], this._contentTypes.push(...t, "application/json"), e && this._contentTypes.push("application/jwt");
  }
  async fetchWithTimeout(t, e = {}) {
    const { timeoutInSeconds: r, ...n } = e;
    if (!r) return await fetch(t, n);
    const s = new AbortController(), i = setTimeout(() => s.abort(), 1e3 * r);
    try {
      return await fetch(t, { ...e, signal: s.signal });
    } catch (a) {
      throw a instanceof DOMException && a.name === "AbortError" ? new ea("Network timed out") : a;
    } finally {
      clearTimeout(i);
    }
  }
  async getJson(t, { token: e, credentials: r, timeoutInSeconds: n } = {}) {
    const s = this._logger.create("getJson"), i = { Accept: this._contentTypes.join(", ") };
    let a;
    e && (s.debug("token passed, setting Authorization header"), i.Authorization = "Bearer " + e), this._appendExtraHeaders(i);
    try {
      s.debug("url:", t), a = await this.fetchWithTimeout(t, { method: "GET", headers: i, timeoutInSeconds: n, credentials: r });
    } catch (u) {
      throw s.error("Network Error"), u;
    }
    s.debug("HTTP response received, status", a.status);
    const o = a.headers.get("Content-Type");
    if (o && !this._contentTypes.find((u) => o.startsWith(u)) && s.throw(new Error(`Invalid response Content-Type: ${o ?? "undefined"}, from URL: ${t}`)), a.ok && this._jwtHandler && (o == null ? void 0 : o.startsWith("application/jwt"))) return await this._jwtHandler(await a.text());
    let c;
    try {
      c = await a.json();
    } catch (u) {
      throw s.error("Error parsing JSON response", u), a.ok ? u : new Error(`${a.statusText} (${a.status})`);
    }
    if (!a.ok)
      throw s.error("Error from server:", c), c.error ? new Bt(c) : new Error(`${a.statusText} (${a.status}): ${JSON.stringify(c)}`);
    return c;
  }
  async postForm(t, { body: e, basicAuth: r, timeoutInSeconds: n, initCredentials: s, extraHeaders: i }) {
    const a = this._logger.create("postForm"), o = { Accept: this._contentTypes.join(", "), "Content-Type": "application/x-www-form-urlencoded", ...i };
    let c;
    r !== void 0 && (o.Authorization = "Basic " + r), this._appendExtraHeaders(o);
    try {
      a.debug("url:", t), c = await this.fetchWithTimeout(t, { method: "POST", headers: o, body: e, timeoutInSeconds: n, credentials: s });
    } catch (v) {
      throw a.error("Network error"), v;
    }
    a.debug("HTTP response received, status", c.status);
    const u = c.headers.get("Content-Type");
    if (u && !this._contentTypes.find((v) => u.startsWith(v))) throw new Error(`Invalid response Content-Type: ${u ?? "undefined"}, from URL: ${t}`);
    const l = await c.text();
    let p = {};
    if (l) try {
      p = JSON.parse(l);
    } catch (v) {
      throw a.error("Error parsing JSON response", v), c.ok ? v : new Error(`${c.statusText} (${c.status})`);
    }
    if (!c.ok) {
      if (a.error("Error from server:", p), c.headers.has("dpop-nonce")) {
        const v = c.headers.get("dpop-nonce");
        throw new ai(v, `${JSON.stringify(p)}`);
      }
      throw p.error ? new Bt(p, e) : new Error(`${c.statusText} (${c.status}): ${JSON.stringify(p)}`);
    }
    return p;
  }
  _appendExtraHeaders(t) {
    const e = this._logger.create("appendExtraHeaders"), r = Object.keys(this._extraHeaders), n = ["accept", "content-type"], s = ["authorization"];
    r.length !== 0 && r.forEach((i) => {
      if (n.includes(i.toLocaleLowerCase())) return void e.warn("Protected header could not be set", i, n);
      if (s.includes(i.toLocaleLowerCase()) && Object.keys(t).includes(i)) return void e.warn("Header could not be overridden", i, s);
      const a = typeof this._extraHeaders[i] == "function" ? this._extraHeaders[i]() : this._extraHeaders[i];
      a && a !== "" && (t[i] = a);
    });
  }
}, fg = class {
  constructor(t) {
    this._settings = t, this._logger = new ue("MetadataService"), this._signingKeys = null, this._metadata = null, this._metadataUrl = this._settings.metadataUrl, this._jsonService = new ta(["application/jwk-set+json"], null, this._settings.extraHeaders), this._settings.signingKeys && (this._logger.debug("using signingKeys from settings"), this._signingKeys = this._settings.signingKeys), this._settings.metadata && (this._logger.debug("using metadata from settings"), this._metadata = this._settings.metadata), this._settings.fetchRequestCredentials && (this._logger.debug("using fetchRequestCredentials from settings"), this._fetchRequestCredentials = this._settings.fetchRequestCredentials);
  }
  resetSigningKeys() {
    this._signingKeys = null;
  }
  async getMetadata() {
    const t = this._logger.create("getMetadata");
    if (this._metadata) return t.debug("using cached values"), this._metadata;
    if (!this._metadataUrl) throw t.throw(new Error("No authority or metadataUrl configured on settings")), null;
    t.debug("getting metadata from", this._metadataUrl);
    const e = await this._jsonService.getJson(this._metadataUrl, { credentials: this._fetchRequestCredentials, timeoutInSeconds: this._settings.requestTimeoutInSeconds });
    return t.debug("merging remote JSON with seed metadata"), this._metadata = Object.assign({}, e, this._settings.metadataSeed), this._metadata;
  }
  getIssuer() {
    return this._getMetadataProperty("issuer");
  }
  getAuthorizationEndpoint() {
    return this._getMetadataProperty("authorization_endpoint");
  }
  getUserInfoEndpoint() {
    return this._getMetadataProperty("userinfo_endpoint");
  }
  getTokenEndpoint(t = !0) {
    return this._getMetadataProperty("token_endpoint", t);
  }
  getCheckSessionIframe() {
    return this._getMetadataProperty("check_session_iframe", !0);
  }
  getEndSessionEndpoint() {
    return this._getMetadataProperty("end_session_endpoint", !0);
  }
  getRevocationEndpoint(t = !0) {
    return this._getMetadataProperty("revocation_endpoint", t);
  }
  getKeysEndpoint(t = !0) {
    return this._getMetadataProperty("jwks_uri", t);
  }
  async _getMetadataProperty(t, e = !1) {
    const r = this._logger.create(`_getMetadataProperty('${t}')`), n = await this.getMetadata();
    if (r.debug("resolved"), n[t] === void 0) {
      if (e === !0) return void r.warn("Metadata does not contain optional property");
      r.throw(new Error("Metadata does not contain property " + t));
    }
    return n[t];
  }
  async getSigningKeys() {
    const t = this._logger.create("getSigningKeys");
    if (this._signingKeys) return t.debug("returning signingKeys from cache"), this._signingKeys;
    const e = await this.getKeysEndpoint(!1);
    t.debug("got jwks_uri", e);
    const r = await this._jsonService.getJson(e, { timeoutInSeconds: this._settings.requestTimeoutInSeconds });
    if (t.debug("got key set", r), !Array.isArray(r.keys)) throw t.throw(new Error("Missing keys on keyset")), null;
    return this._signingKeys = r.keys, this._signingKeys;
  }
}, ra = class {
  constructor({ prefix: t = "oidc.", store: e = localStorage } = {}) {
    this._logger = new ue("WebStorageStateStore"), this._store = e, this._prefix = t;
  }
  async set(t, e) {
    this._logger.create(`set('${t}')`), t = this._prefix + t, await this._store.setItem(t, e);
  }
  async get(t) {
    return this._logger.create(`get('${t}')`), t = this._prefix + t, await this._store.getItem(t);
  }
  async remove(t) {
    this._logger.create(`remove('${t}')`), t = this._prefix + t;
    const e = await this._store.getItem(t);
    return await this._store.removeItem(t), e;
  }
  async getAllKeys() {
    this._logger.create("getAllKeys");
    const t = await this._store.length, e = [];
    for (let r = 0; r < t; r++) {
      const n = await this._store.key(r);
      n && n.indexOf(this._prefix) === 0 && e.push(n.substr(this._prefix.length));
    }
    return e;
  }
}, oi = class {
  constructor({ authority: t, metadataUrl: e, metadata: r, signingKeys: n, metadataSeed: s, client_id: i, client_secret: a, response_type: o = "code", scope: c = "openid", redirect_uri: u, post_logout_redirect_uri: l, client_authentication: p = "client_secret_post", token_endpoint_auth_signing_alg: v = "HS256", prompt: w, display: b, max_age: E, ui_locales: _, acr_values: m, resource: h, response_mode: g, filterProtocolClaims: $ = !0, loadUserInfo: d = !1, requestTimeoutInSeconds: f, staleStateAgeInSeconds: y = 900, mergeClaimsStrategy: k = { array: "replace" }, disablePKCE: I = !1, stateStore: N, revokeTokenAdditionalContentTypes: R, fetchRequestCredentials: j, refreshTokenAllowedScope: F, extraQueryParams: H = {}, extraTokenParams: se = {}, extraHeaders: ke = {}, dpop: je, omitScopeWhenRequesting: fe = !1 }) {
    var Je;
    if (this.authority = t, e ? this.metadataUrl = e : (this.metadataUrl = t, t && (this.metadataUrl.endsWith("/") || (this.metadataUrl += "/"), this.metadataUrl += ".well-known/openid-configuration")), this.metadata = r, this.metadataSeed = s, this.signingKeys = n, this.client_id = i, this.client_secret = a, this.response_type = o, this.scope = c, this.redirect_uri = u, this.post_logout_redirect_uri = l, this.client_authentication = p, this.token_endpoint_auth_signing_alg = v, this.prompt = w, this.display = b, this.max_age = E, this.ui_locales = _, this.acr_values = m, this.resource = h, this.response_mode = g, this.filterProtocolClaims = $ == null || $, this.loadUserInfo = !!d, this.staleStateAgeInSeconds = y, this.mergeClaimsStrategy = k, this.omitScopeWhenRequesting = fe, this.disablePKCE = !!I, this.revokeTokenAdditionalContentTypes = R, this.fetchRequestCredentials = j || "same-origin", this.requestTimeoutInSeconds = f, N) this.stateStore = N;
    else {
      const L = typeof window < "u" ? window.localStorage : new Nu();
      this.stateStore = new ra({ store: L });
    }
    if (this.refreshTokenAllowedScope = F, this.extraQueryParams = H, this.extraTokenParams = se, this.extraHeaders = ke, this.dpop = je, this.dpop && !((Je = this.dpop) != null && Je.store)) throw new Error("A DPoPStore is required when dpop is enabled");
  }
}, pg = class {
  constructor(t, e) {
    this._settings = t, this._metadataService = e, this._logger = new ue("UserInfoService"), this._getClaimsFromJwt = async (r) => {
      const n = this._logger.create("_getClaimsFromJwt");
      try {
        const s = Or.decode(r);
        return n.debug("JWT decoding successful"), s;
      } catch (s) {
        throw n.error("Error parsing JWT response"), s;
      }
    }, this._jsonService = new ta(void 0, this._getClaimsFromJwt, this._settings.extraHeaders);
  }
  async getClaims(t) {
    const e = this._logger.create("getClaims");
    t || this._logger.throw(new Error("No token passed"));
    const r = await this._metadataService.getUserInfoEndpoint();
    e.debug("got userinfo url", r);
    const n = await this._jsonService.getJson(r, { token: t, credentials: this._settings.fetchRequestCredentials, timeoutInSeconds: this._settings.requestTimeoutInSeconds });
    return e.debug("got claims", n), n;
  }
}, xu = class {
  constructor(t, e) {
    this._settings = t, this._metadataService = e, this._logger = new ue("TokenClient"), this._jsonService = new ta(this._settings.revokeTokenAdditionalContentTypes, null, this._settings.extraHeaders);
  }
  async exchangeCode({ grant_type: t = "authorization_code", redirect_uri: e = this._settings.redirect_uri, client_id: r = this._settings.client_id, client_secret: n = this._settings.client_secret, extraHeaders: s, ...i }) {
    const a = this._logger.create("exchangeCode");
    r || a.throw(new Error("A client_id is required")), e || a.throw(new Error("A redirect_uri is required")), i.code || a.throw(new Error("A code is required"));
    const o = new URLSearchParams({ grant_type: t, redirect_uri: e });
    for (const [p, v] of Object.entries(i)) v != null && o.set(p, v);
    if ((this._settings.client_authentication === "client_secret_basic" || this._settings.client_authentication === "client_secret_jwt") && n == null) throw a.throw(new Error("A client_secret is required")), null;
    let c;
    const u = await this._metadataService.getTokenEndpoint(!1);
    switch (this._settings.client_authentication) {
      case "client_secret_basic":
        c = qe.generateBasicAuth(r, n);
        break;
      case "client_secret_post":
        o.append("client_id", r), n && o.append("client_secret", n);
        break;
      case "client_secret_jwt": {
        const p = await qe.generateClientAssertionJwt(r, n, u, this._settings.token_endpoint_auth_signing_alg);
        o.append("client_id", r), o.append("client_assertion_type", "urn:ietf:params:oauth:client-assertion-type:jwt-bearer"), o.append("client_assertion", p);
        break;
      }
    }
    a.debug("got token endpoint");
    const l = await this._jsonService.postForm(u, { body: o, basicAuth: c, timeoutInSeconds: this._settings.requestTimeoutInSeconds, initCredentials: this._settings.fetchRequestCredentials, extraHeaders: s });
    return a.debug("got response"), l;
  }
  async exchangeCredentials({ grant_type: t = "password", client_id: e = this._settings.client_id, client_secret: r = this._settings.client_secret, scope: n = this._settings.scope, ...s }) {
    const i = this._logger.create("exchangeCredentials");
    e || i.throw(new Error("A client_id is required"));
    const a = new URLSearchParams({ grant_type: t });
    this._settings.omitScopeWhenRequesting || a.set("scope", n);
    for (const [l, p] of Object.entries(s)) p != null && a.set(l, p);
    if ((this._settings.client_authentication === "client_secret_basic" || this._settings.client_authentication === "client_secret_jwt") && r == null) throw i.throw(new Error("A client_secret is required")), null;
    let o;
    const c = await this._metadataService.getTokenEndpoint(!1);
    switch (this._settings.client_authentication) {
      case "client_secret_basic":
        o = qe.generateBasicAuth(e, r);
        break;
      case "client_secret_post":
        a.append("client_id", e), r && a.append("client_secret", r);
        break;
      case "client_secret_jwt": {
        const l = await qe.generateClientAssertionJwt(e, r, c, this._settings.token_endpoint_auth_signing_alg);
        a.append("client_id", e), a.append("client_assertion_type", "urn:ietf:params:oauth:client-assertion-type:jwt-bearer"), a.append("client_assertion", l);
        break;
      }
    }
    i.debug("got token endpoint");
    const u = await this._jsonService.postForm(c, { body: a, basicAuth: o, timeoutInSeconds: this._settings.requestTimeoutInSeconds, initCredentials: this._settings.fetchRequestCredentials });
    return i.debug("got response"), u;
  }
  async exchangeRefreshToken({ grant_type: t = "refresh_token", client_id: e = this._settings.client_id, client_secret: r = this._settings.client_secret, timeoutInSeconds: n, extraHeaders: s, ...i }) {
    const a = this._logger.create("exchangeRefreshToken");
    e || a.throw(new Error("A client_id is required")), i.refresh_token || a.throw(new Error("A refresh_token is required"));
    const o = new URLSearchParams({ grant_type: t });
    for (const [p, v] of Object.entries(i)) Array.isArray(v) ? v.forEach((w) => o.append(p, w)) : v != null && o.set(p, v);
    if ((this._settings.client_authentication === "client_secret_basic" || this._settings.client_authentication === "client_secret_jwt") && r == null) throw a.throw(new Error("A client_secret is required")), null;
    let c;
    const u = await this._metadataService.getTokenEndpoint(!1);
    switch (this._settings.client_authentication) {
      case "client_secret_basic":
        c = qe.generateBasicAuth(e, r);
        break;
      case "client_secret_post":
        o.append("client_id", e), r && o.append("client_secret", r);
        break;
      case "client_secret_jwt": {
        const p = await qe.generateClientAssertionJwt(e, r, u, this._settings.token_endpoint_auth_signing_alg);
        o.append("client_id", e), o.append("client_assertion_type", "urn:ietf:params:oauth:client-assertion-type:jwt-bearer"), o.append("client_assertion", p);
        break;
      }
    }
    a.debug("got token endpoint");
    const l = await this._jsonService.postForm(u, { body: o, basicAuth: c, timeoutInSeconds: n, initCredentials: this._settings.fetchRequestCredentials, extraHeaders: s });
    return a.debug("got response"), l;
  }
  async revoke(t) {
    var e;
    const r = this._logger.create("revoke");
    t.token || r.throw(new Error("A token is required"));
    const n = await this._metadataService.getRevocationEndpoint(!1);
    r.debug(`got revocation endpoint, revoking ${(e = t.token_type_hint) != null ? e : "default token type"}`);
    const s = new URLSearchParams();
    for (const [i, a] of Object.entries(t)) a != null && s.set(i, a);
    s.set("client_id", this._settings.client_id), this._settings.client_secret && s.set("client_secret", this._settings.client_secret), await this._jsonService.postForm(n, { body: s, timeoutInSeconds: this._settings.requestTimeoutInSeconds }), r.debug("got response");
  }
}, mg = class {
  constructor(t, e, r) {
    this._settings = t, this._metadataService = e, this._claimsService = r, this._logger = new ue("ResponseValidator"), this._userInfoService = new pg(this._settings, this._metadataService), this._tokenClient = new xu(this._settings, this._metadataService);
  }
  async validateSigninResponse(t, e, r) {
    const n = this._logger.create("validateSigninResponse");
    this._processSigninState(t, e), n.debug("state processed"), await this._processCode(t, e, r), n.debug("code processed"), t.isOpenId && this._validateIdTokenAttributes(t), n.debug("tokens validated"), await this._processClaims(t, e == null ? void 0 : e.skipUserInfo, t.isOpenId), n.debug("claims processed");
  }
  async validateCredentialsResponse(t, e) {
    const r = this._logger.create("validateCredentialsResponse"), n = t.isOpenId && !!t.id_token;
    n && this._validateIdTokenAttributes(t), r.debug("tokens validated"), await this._processClaims(t, e, n), r.debug("claims processed");
  }
  async validateRefreshResponse(t, e) {
    const r = this._logger.create("validateRefreshResponse");
    t.userState = e.data, t.session_state != null || (t.session_state = e.session_state), t.scope != null || (t.scope = e.scope), t.isOpenId && t.id_token && (this._validateIdTokenAttributes(t, e.id_token), r.debug("ID Token validated")), t.id_token || (t.id_token = e.id_token, t.profile = e.profile);
    const n = t.isOpenId && !!t.id_token;
    await this._processClaims(t, !1, n), r.debug("claims processed");
  }
  validateSignoutResponse(t, e) {
    const r = this._logger.create("validateSignoutResponse");
    if (e.id !== t.state && r.throw(new Error("State does not match")), r.debug("state validated"), t.userState = e.data, t.error) throw r.warn("Response was error", t.error), new Bt(t);
  }
  _processSigninState(t, e) {
    const r = this._logger.create("_processSigninState");
    if (e.id !== t.state && r.throw(new Error("State does not match")), e.client_id || r.throw(new Error("No client_id on state")), e.authority || r.throw(new Error("No authority on state")), this._settings.authority !== e.authority && r.throw(new Error("authority mismatch on settings vs. signin state")), this._settings.client_id && this._settings.client_id !== e.client_id && r.throw(new Error("client_id mismatch on settings vs. signin state")), r.debug("state validated"), t.userState = e.data, t.url_state = e.url_state, t.scope != null || (t.scope = e.scope), t.error) throw r.warn("Response was error", t.error), new Bt(t);
    e.code_verifier && !t.code && r.throw(new Error("Expected code in response"));
  }
  async _processClaims(t, e = !1, r = !0) {
    const n = this._logger.create("_processClaims");
    if (t.profile = this._claimsService.filterProtocolClaims(t.profile), e || !this._settings.loadUserInfo || !t.access_token) return void n.debug("not loading user info");
    n.debug("loading user info");
    const s = await this._userInfoService.getClaims(t.access_token);
    n.debug("user info claims received from user info endpoint"), r && s.sub !== t.profile.sub && n.throw(new Error("subject from UserInfo response does not match subject in ID Token")), t.profile = this._claimsService.mergeClaims(t.profile, this._claimsService.filterProtocolClaims(s)), n.debug("user info claims received, updated profile:", t.profile);
  }
  async _processCode(t, e, r) {
    const n = this._logger.create("_processCode");
    if (t.code) {
      n.debug("Validating code");
      const s = await this._tokenClient.exchangeCode({ client_id: e.client_id, client_secret: e.client_secret, code: t.code, redirect_uri: e.redirect_uri, code_verifier: e.code_verifier, extraHeaders: r, ...e.extraTokenParams });
      Object.assign(t, s);
    } else n.debug("No code to process");
  }
  _validateIdTokenAttributes(t, e) {
    var r;
    const n = this._logger.create("_validateIdTokenAttributes");
    n.debug("decoding ID Token JWT");
    const s = Or.decode((r = t.id_token) != null ? r : "");
    if (s.sub || n.throw(new Error("ID Token is missing a subject claim")), e) {
      const i = Or.decode(e);
      s.sub !== i.sub && n.throw(new Error("sub in id_token does not match current sub")), s.auth_time && s.auth_time !== i.auth_time && n.throw(new Error("auth_time in id_token does not match original auth_time")), s.azp && s.azp !== i.azp && n.throw(new Error("azp in id_token does not match original azp")), !s.azp && i.azp && n.throw(new Error("azp not in id_token, but present in original id_token"));
    }
    t.profile = s;
  }
}, Wn = class ci {
  constructor(e) {
    this.id = e.id || qe.generateUUIDv4(), this.data = e.data, e.created && e.created > 0 ? this.created = e.created : this.created = Pt.getEpochTime(), this.request_type = e.request_type, this.url_state = e.url_state;
  }
  toStorageString() {
    return new ue("State").create("toStorageString"), JSON.stringify({ id: this.id, data: this.data, created: this.created, request_type: this.request_type, url_state: this.url_state });
  }
  static fromStorageString(e) {
    return ue.createStatic("State", "fromStorageString"), Promise.resolve(new ci(JSON.parse(e)));
  }
  static async clearStaleState(e, r) {
    const n = ue.createStatic("State", "clearStaleState"), s = Pt.getEpochTime() - r, i = await e.getAllKeys();
    n.debug("got keys", i);
    for (let a = 0; a < i.length; a++) {
      const o = i[a], c = await e.get(o);
      let u = !1;
      if (c) try {
        const l = await ci.fromStorageString(c);
        n.debug("got item from key:", o, l.created), l.created <= s && (u = !0);
      } catch (l) {
        n.error("Error parsing state for key:", o, l), u = !0;
      }
      else n.debug("no item in storage for key:", o), u = !0;
      u && (n.debug("removed item for key:", o), e.remove(o));
    }
  }
}, ju = class ui extends Wn {
  constructor(e) {
    super(e), this.code_verifier = e.code_verifier, this.code_challenge = e.code_challenge, this.authority = e.authority, this.client_id = e.client_id, this.redirect_uri = e.redirect_uri, this.scope = e.scope, this.client_secret = e.client_secret, this.extraTokenParams = e.extraTokenParams, this.response_mode = e.response_mode, this.skipUserInfo = e.skipUserInfo;
  }
  static async create(e) {
    const r = e.code_verifier === !0 ? qe.generateCodeVerifier() : e.code_verifier || void 0, n = r ? await qe.generateCodeChallenge(r) : void 0;
    return new ui({ ...e, code_verifier: r, code_challenge: n });
  }
  toStorageString() {
    return new ue("SigninState").create("toStorageString"), JSON.stringify({ id: this.id, data: this.data, created: this.created, request_type: this.request_type, url_state: this.url_state, code_verifier: this.code_verifier, authority: this.authority, client_id: this.client_id, redirect_uri: this.redirect_uri, scope: this.scope, client_secret: this.client_secret, extraTokenParams: this.extraTokenParams, response_mode: this.response_mode, skipUserInfo: this.skipUserInfo });
  }
  static fromStorageString(e) {
    ue.createStatic("SigninState", "fromStorageString");
    const r = JSON.parse(e);
    return ui.create(r);
  }
}, zu = class qu {
  constructor(e) {
    this.url = e.url, this.state = e.state;
  }
  static async create({ url: e, authority: r, client_id: n, redirect_uri: s, response_type: i, scope: a, state_data: o, response_mode: c, request_type: u, client_secret: l, nonce: p, url_state: v, resource: w, skipUserInfo: b, extraQueryParams: E, extraTokenParams: _, disablePKCE: m, dpopJkt: h, omitScopeWhenRequesting: g, ...$ }) {
    if (!e) throw this._logger.error("create: No url passed"), new Error("url");
    if (!n) throw this._logger.error("create: No client_id passed"), new Error("client_id");
    if (!s) throw this._logger.error("create: No redirect_uri passed"), new Error("redirect_uri");
    if (!i) throw this._logger.error("create: No response_type passed"), new Error("response_type");
    if (!a) throw this._logger.error("create: No scope passed"), new Error("scope");
    if (!r) throw this._logger.error("create: No authority passed"), new Error("authority");
    const d = await ju.create({ data: o, request_type: u, url_state: v, code_verifier: !m, client_id: n, authority: r, redirect_uri: s, response_mode: c, client_secret: l, scope: a, extraTokenParams: _, skipUserInfo: b }), f = new URL(e);
    f.searchParams.append("client_id", n), f.searchParams.append("redirect_uri", s), f.searchParams.append("response_type", i), g || f.searchParams.append("scope", a), p && f.searchParams.append("nonce", p), h && f.searchParams.append("dpop_jkt", h);
    let y = d.id;
    v && (y = `${y}${ar}${v}`), f.searchParams.append("state", y), d.code_challenge && (f.searchParams.append("code_challenge", d.code_challenge), f.searchParams.append("code_challenge_method", "S256")), w && (Array.isArray(w) ? w : [w]).forEach((k) => f.searchParams.append("resource", k));
    for (const [k, I] of Object.entries({ response_mode: c, ...$, ...E })) I != null && f.searchParams.append(k, I.toString());
    return new qu({ url: f.href, state: d });
  }
};
zu._logger = new ue("SigninRequest");
var gg = zu, $s = class {
  constructor(t) {
    if (this.access_token = "", this.token_type = "", this.profile = {}, this.state = t.get("state"), this.session_state = t.get("session_state"), this.state) {
      const e = decodeURIComponent(this.state).split(ar);
      this.state = e[0], e.length > 1 && (this.url_state = e.slice(1).join(ar));
    }
    this.error = t.get("error"), this.error_description = t.get("error_description"), this.error_uri = t.get("error_uri"), this.code = t.get("code");
  }
  get expires_in() {
    if (this.expires_at !== void 0) return this.expires_at - Pt.getEpochTime();
  }
  set expires_in(t) {
    typeof t == "string" && (t = Number(t)), t !== void 0 && t >= 0 && (this.expires_at = Math.floor(t) + Pt.getEpochTime());
  }
  get isOpenId() {
    var t;
    return ((t = this.scope) == null ? void 0 : t.split(" ").includes("openid")) || !!this.id_token;
  }
}, _g = class {
  constructor({ url: t, state_data: e, id_token_hint: r, post_logout_redirect_uri: n, extraQueryParams: s, request_type: i, client_id: a, url_state: o }) {
    if (this._logger = new ue("SignoutRequest"), !t) throw this._logger.error("ctor: No url passed"), new Error("url");
    const c = new URL(t);
    if (r && c.searchParams.append("id_token_hint", r), a && c.searchParams.append("client_id", a), n && (c.searchParams.append("post_logout_redirect_uri", n), e || o)) {
      this.state = new Wn({ data: e, request_type: i, url_state: o });
      let u = this.state.id;
      o && (u = `${u}${ar}${o}`), c.searchParams.append("state", u);
    }
    for (const [u, l] of Object.entries({ ...s })) l != null && c.searchParams.append(u, l.toString());
    this.url = c.href;
  }
}, yg = class {
  constructor(t) {
    if (this.state = t.get("state"), this.state) {
      const e = decodeURIComponent(this.state).split(ar);
      this.state = e[0], e.length > 1 && (this.url_state = e.slice(1).join(ar));
    }
    this.error = t.get("error"), this.error_description = t.get("error_description"), this.error_uri = t.get("error_uri");
  }
}, vg = ["nbf", "jti", "auth_time", "nonce", "acr", "amr", "azp", "at_hash"], wg = ["sub", "iss", "aud", "exp", "iat"], bg = class {
  constructor(t) {
    this._settings = t, this._logger = new ue("ClaimsService");
  }
  filterProtocolClaims(t) {
    const e = { ...t };
    if (this._settings.filterProtocolClaims) {
      let r;
      r = Array.isArray(this._settings.filterProtocolClaims) ? this._settings.filterProtocolClaims : vg;
      for (const n of r) wg.includes(n) || delete e[n];
    }
    return e;
  }
  mergeClaims(t, e) {
    const r = { ...t };
    for (const [n, s] of Object.entries(e)) if (r[n] !== s) if (Array.isArray(r[n]) || Array.isArray(s)) if (this._settings.mergeClaimsStrategy.array == "replace") r[n] = s;
    else {
      const i = Array.isArray(r[n]) ? r[n] : [r[n]];
      for (const a of Array.isArray(s) ? s : [s]) i.includes(a) || i.push(a);
      r[n] = i;
    }
    else typeof r[n] == "object" && typeof s == "object" ? r[n] = this.mergeClaims(r[n], s) : r[n] = s;
    return r;
  }
}, Mu = class {
  constructor(t, e) {
    this.keys = t, this.nonce = e;
  }
}, Sg = class {
  constructor(t, e) {
    this._logger = new ue("OidcClient"), this.settings = t instanceof oi ? t : new oi(t), this.metadataService = e ?? new fg(this.settings), this._claimsService = new bg(this.settings), this._validator = new mg(this.settings, this.metadataService, this._claimsService), this._tokenClient = new xu(this.settings, this.metadataService);
  }
  async createSigninRequest({ state: t, request: e, request_uri: r, request_type: n, id_token_hint: s, login_hint: i, skipUserInfo: a, nonce: o, url_state: c, response_type: u = this.settings.response_type, scope: l = this.settings.scope, redirect_uri: p = this.settings.redirect_uri, prompt: v = this.settings.prompt, display: w = this.settings.display, max_age: b = this.settings.max_age, ui_locales: E = this.settings.ui_locales, acr_values: _ = this.settings.acr_values, resource: m = this.settings.resource, response_mode: h = this.settings.response_mode, extraQueryParams: g = this.settings.extraQueryParams, extraTokenParams: $ = this.settings.extraTokenParams, dpopJkt: d, omitScopeWhenRequesting: f = this.settings.omitScopeWhenRequesting }) {
    const y = this._logger.create("createSigninRequest");
    if (u !== "code") throw new Error("Only the Authorization Code flow (with PKCE) is supported");
    const k = await this.metadataService.getAuthorizationEndpoint();
    y.debug("Received authorization endpoint", k);
    const I = await gg.create({ url: k, authority: this.settings.authority, client_id: this.settings.client_id, redirect_uri: p, response_type: u, scope: l, state_data: t, url_state: c, prompt: v, display: w, max_age: b, ui_locales: E, id_token_hint: s, login_hint: i, acr_values: _, dpopJkt: d, resource: m, request: e, request_uri: r, extraQueryParams: g, extraTokenParams: $, request_type: n, response_mode: h, client_secret: this.settings.client_secret, skipUserInfo: a, nonce: o, disablePKCE: this.settings.disablePKCE, omitScopeWhenRequesting: f });
    await this.clearStaleState();
    const N = I.state;
    return await this.settings.stateStore.set(N.id, N.toStorageString()), I;
  }
  async readSigninResponseState(t, e = !1) {
    const r = this._logger.create("readSigninResponseState"), n = new $s(ii.readParams(t, this.settings.response_mode));
    if (!n.state) throw r.throw(new Error("No state in response")), null;
    const s = await this.settings.stateStore[e ? "remove" : "get"](n.state);
    if (!s) throw r.throw(new Error("No matching state found in storage")), null;
    return { state: await ju.fromStorageString(s), response: n };
  }
  async processSigninResponse(t, e, r = !0) {
    const n = this._logger.create("processSigninResponse"), { state: s, response: i } = await this.readSigninResponseState(t, r);
    if (n.debug("received state from storage; validating response"), this.settings.dpop && this.settings.dpop.store) {
      const a = await this.getDpopProof(this.settings.dpop.store);
      e = { ...e, DPoP: a };
    }
    try {
      await this._validator.validateSigninResponse(i, s, e);
    } catch (a) {
      if (!(a instanceof ai && this.settings.dpop)) throw a;
      {
        const o = await this.getDpopProof(this.settings.dpop.store, a.nonce);
        e.DPoP = o, await this._validator.validateSigninResponse(i, s, e);
      }
    }
    return i;
  }
  async getDpopProof(t, e) {
    let r, n;
    return (await t.getAllKeys()).includes(this.settings.client_id) ? (n = await t.get(this.settings.client_id), n.nonce !== e && e && (n.nonce = e, await t.set(this.settings.client_id, n))) : (r = await qe.generateDPoPKeys(), n = new Mu(r, e), await t.set(this.settings.client_id, n)), await qe.generateDPoPProof({ url: await this.metadataService.getTokenEndpoint(!1), httpMethod: "POST", keyPair: n.keys, nonce: n.nonce });
  }
  async processResourceOwnerPasswordCredentials({ username: t, password: e, skipUserInfo: r = !1, extraTokenParams: n = {} }) {
    const s = await this._tokenClient.exchangeCredentials({ username: t, password: e, ...n }), i = new $s(new URLSearchParams());
    return Object.assign(i, s), await this._validator.validateCredentialsResponse(i, r), i;
  }
  async useRefreshToken({ state: t, redirect_uri: e, resource: r, timeoutInSeconds: n, extraHeaders: s, extraTokenParams: i }) {
    var a;
    const o = this._logger.create("useRefreshToken");
    let c, u;
    if (this.settings.refreshTokenAllowedScope === void 0) c = t.scope;
    else {
      const p = this.settings.refreshTokenAllowedScope.split(" ");
      c = (((a = t.scope) == null ? void 0 : a.split(" ")) || []).filter((v) => p.includes(v)).join(" ");
    }
    if (this.settings.dpop && this.settings.dpop.store) {
      const p = await this.getDpopProof(this.settings.dpop.store);
      s = { ...s, DPoP: p };
    }
    try {
      u = await this._tokenClient.exchangeRefreshToken({ refresh_token: t.refresh_token, scope: c, redirect_uri: e, resource: r, timeoutInSeconds: n, extraHeaders: s, ...i });
    } catch (p) {
      if (!(p instanceof ai && this.settings.dpop)) throw p;
      s.DPoP = await this.getDpopProof(this.settings.dpop.store, p.nonce), u = await this._tokenClient.exchangeRefreshToken({ refresh_token: t.refresh_token, scope: c, redirect_uri: e, resource: r, timeoutInSeconds: n, extraHeaders: s, ...i });
    }
    const l = new $s(new URLSearchParams());
    return Object.assign(l, u), o.debug("validating response", l), await this._validator.validateRefreshResponse(l, { ...t, scope: c }), l;
  }
  async createSignoutRequest({ state: t, id_token_hint: e, client_id: r, request_type: n, url_state: s, post_logout_redirect_uri: i = this.settings.post_logout_redirect_uri, extraQueryParams: a = this.settings.extraQueryParams } = {}) {
    const o = this._logger.create("createSignoutRequest"), c = await this.metadataService.getEndSessionEndpoint();
    if (!c) throw o.throw(new Error("No end session endpoint")), null;
    o.debug("Received end session endpoint", c), r || !i || e || (r = this.settings.client_id);
    const u = new _g({ url: c, id_token_hint: e, client_id: r, post_logout_redirect_uri: i, state_data: t, extraQueryParams: a, request_type: n, url_state: s });
    await this.clearStaleState();
    const l = u.state;
    return l && (o.debug("Signout request has state to persist"), await this.settings.stateStore.set(l.id, l.toStorageString())), u;
  }
  async readSignoutResponseState(t, e = !1) {
    const r = this._logger.create("readSignoutResponseState"), n = new yg(ii.readParams(t, this.settings.response_mode));
    if (!n.state) {
      if (r.debug("No state in response"), n.error) throw r.warn("Response was error:", n.error), new Bt(n);
      return { state: void 0, response: n };
    }
    const s = await this.settings.stateStore[e ? "remove" : "get"](n.state);
    if (!s) throw r.throw(new Error("No matching state found in storage")), null;
    return { state: await Wn.fromStorageString(s), response: n };
  }
  async processSignoutResponse(t) {
    const e = this._logger.create("processSignoutResponse"), { state: r, response: n } = await this.readSignoutResponseState(t, !0);
    return r ? (e.debug("Received state from storage; validating response"), this._validator.validateSignoutResponse(n, r)) : e.debug("No state from storage; skipping response validation"), n;
  }
  clearStaleState() {
    return this._logger.create("clearStaleState"), Wn.clearStaleState(this.settings.stateStore, this.settings.staleStateAgeInSeconds);
  }
  async revokeToken(t, e) {
    return this._logger.create("revokeToken"), await this._tokenClient.revoke({ token: t, token_type_hint: e });
  }
}, kg = class {
  constructor(t) {
    this._userManager = t, this._logger = new ue("SessionMonitor"), this._start = async (e) => {
      const r = e.session_state;
      if (!r) return;
      const n = this._logger.create("_start");
      if (e.profile ? (this._sub = e.profile.sub, n.debug("session_state", r, ", sub", this._sub)) : (this._sub = void 0, n.debug("session_state", r, ", anonymous user")), this._checkSessionIFrame) this._checkSessionIFrame.start(r);
      else try {
        const s = await this._userManager.metadataService.getCheckSessionIframe();
        if (s) {
          n.debug("initializing check session iframe");
          const i = this._userManager.settings.client_id, a = this._userManager.settings.checkSessionIntervalInSeconds, o = this._userManager.settings.stopCheckSessionOnError, c = new hg(this._callback, i, s, a, o);
          await c.load(), this._checkSessionIFrame = c, c.start(r);
        } else n.warn("no check session iframe found in the metadata");
      } catch (s) {
        n.error("Error from getCheckSessionIframe:", s instanceof Error ? s.message : s);
      }
    }, this._stop = () => {
      const e = this._logger.create("_stop");
      if (this._sub = void 0, this._checkSessionIFrame && this._checkSessionIFrame.stop(), this._userManager.settings.monitorAnonymousSession) {
        const r = setInterval(async () => {
          clearInterval(r);
          try {
            const n = await this._userManager.querySessionStatus();
            if (n) {
              const s = { session_state: n.session_state, profile: n.sub ? { sub: n.sub } : null };
              this._start(s);
            }
          } catch (n) {
            e.error("error from querySessionStatus", n instanceof Error ? n.message : n);
          }
        }, 1e3);
      }
    }, this._callback = async () => {
      const e = this._logger.create("_callback");
      try {
        const r = await this._userManager.querySessionStatus();
        let n = !0;
        r && this._checkSessionIFrame ? r.sub === this._sub ? (n = !1, this._checkSessionIFrame.start(r.session_state), e.debug("same sub still logged in at OP, session state has changed, restarting check session iframe; session_state", r.session_state), await this._userManager.events._raiseUserSessionChanged()) : e.debug("different subject signed into OP", r.sub) : e.debug("subject no longer signed into OP"), n ? this._sub ? await this._userManager.events._raiseUserSignedOut() : await this._userManager.events._raiseUserSignedIn() : e.debug("no change in session detected, no event to raise");
      } catch (r) {
        this._sub && (e.debug("Error calling queryCurrentSigninSession; raising signed out event", r), await this._userManager.events._raiseUserSignedOut());
      }
    }, t || this._logger.throw(new Error("No user manager passed")), this._userManager.events.addUserLoaded(this._start), this._userManager.events.addUserUnloaded(this._stop), this._init().catch((e) => {
      this._logger.error(e);
    });
  }
  async _init() {
    this._logger.create("_init");
    const t = await this._userManager.getUser();
    if (t) this._start(t);
    else if (this._userManager.settings.monitorAnonymousSession) {
      const e = await this._userManager.querySessionStatus();
      if (e) {
        const r = { session_state: e.session_state, profile: e.sub ? { sub: e.sub } : null };
        this._start(r);
      }
    }
  }
}, qn = class Du {
  constructor(e) {
    var r;
    this.id_token = e.id_token, this.session_state = (r = e.session_state) != null ? r : null, this.access_token = e.access_token, this.refresh_token = e.refresh_token, this.token_type = e.token_type, this.scope = e.scope, this.profile = e.profile, this.expires_at = e.expires_at, this.state = e.userState, this.url_state = e.url_state;
  }
  get expires_in() {
    if (this.expires_at !== void 0) return this.expires_at - Pt.getEpochTime();
  }
  set expires_in(e) {
    e !== void 0 && (this.expires_at = Math.floor(e) + Pt.getEpochTime());
  }
  get expired() {
    const e = this.expires_in;
    if (e !== void 0) return e <= 0;
  }
  get scopes() {
    var e, r;
    return (r = (e = this.scope) == null ? void 0 : e.split(" ")) != null ? r : [];
  }
  toStorageString() {
    return new ue("User").create("toStorageString"), JSON.stringify({ id_token: this.id_token, session_state: this.session_state, access_token: this.access_token, refresh_token: this.refresh_token, token_type: this.token_type, scope: this.scope, profile: this.profile, expires_at: this.expires_at });
  }
  static fromStorageString(e) {
    return ue.createStatic("User", "fromStorageString"), new Du(JSON.parse(e));
  }
}, xa = "oidc-client", Uu = class {
  constructor() {
    this._abort = new Nt("Window navigation aborted"), this._disposeHandlers = /* @__PURE__ */ new Set(), this._window = null;
  }
  async navigate(t) {
    const e = this._logger.create("navigate");
    if (!this._window) throw new Error("Attempted to navigate on a disposed window");
    e.debug("setting URL in window"), this._window.location.replace(t.url);
    const { url: r, keepOpen: n } = await new Promise((s, i) => {
      const a = (c) => {
        var u;
        const l = c.data, p = (u = t.scriptOrigin) != null ? u : window.location.origin;
        if (c.origin === p && (l == null ? void 0 : l.source) === xa) {
          try {
            const v = ii.readParams(l.url, t.response_mode).get("state");
            if (v || e.warn("no state found in response url"), c.source !== this._window && v !== t.state) return;
          } catch {
            this._dispose(), i(new Error("Invalid response from window"));
          }
          s(l);
        }
      };
      window.addEventListener("message", a, !1), this._disposeHandlers.add(() => window.removeEventListener("message", a, !1));
      const o = new BroadcastChannel(`oidc-client-popup-${t.state}`);
      o.addEventListener("message", a, !1), this._disposeHandlers.add(() => o.close()), this._disposeHandlers.add(this._abort.addHandler((c) => {
        this._dispose(), i(c);
      }));
    });
    return e.debug("got response from window"), this._dispose(), n || this.close(), { url: r };
  }
  _dispose() {
    this._logger.create("_dispose");
    for (const t of this._disposeHandlers) t();
    this._disposeHandlers.clear();
  }
  static _notifyParent(t, e, r = !1, n = window.location.origin) {
    const s = { source: xa, url: e, keepOpen: r }, i = new ue("_notifyParent");
    if (t) i.debug("With parent. Using parent.postMessage."), t.postMessage(s, n);
    else {
      i.debug("No parent. Using BroadcastChannel.");
      const a = new URL(e).searchParams.get("state");
      if (!a) throw new Error("No parent and no state in URL. Can't complete notification.");
      const o = new BroadcastChannel(`oidc-client-popup-${a}`);
      o.postMessage(s), o.close();
    }
  }
}, Lu = { location: !1, toolbar: !1, height: 640, closePopupWindowAfterInSeconds: -1 }, Zu = "_blank", $g = 60, Eg = 2, Tg = class extends oi {
  constructor(t) {
    const { popup_redirect_uri: e = t.redirect_uri, popup_post_logout_redirect_uri: r = t.post_logout_redirect_uri, popupWindowFeatures: n = Lu, popupWindowTarget: s = Zu, redirectMethod: i = "assign", redirectTarget: a = "self", iframeNotifyParentOrigin: o = t.iframeNotifyParentOrigin, iframeScriptOrigin: c = t.iframeScriptOrigin, requestTimeoutInSeconds: u, silent_redirect_uri: l = t.redirect_uri, silentRequestTimeoutInSeconds: p, automaticSilentRenew: v = !0, validateSubOnSilentRenew: w = !0, includeIdTokenInSilentRenew: b = !1, monitorSession: E = !1, monitorAnonymousSession: _ = !1, checkSessionIntervalInSeconds: m = Eg, query_status_response_type: h = "code", stopCheckSessionOnError: g = !0, revokeTokenTypes: $ = ["access_token", "refresh_token"], revokeTokensOnSignout: d = !1, includeIdTokenInSilentSignout: f = !1, accessTokenExpiringNotificationTimeInSeconds: y = $g, userStore: k } = t;
    if (super(t), this.popup_redirect_uri = e, this.popup_post_logout_redirect_uri = r, this.popupWindowFeatures = n, this.popupWindowTarget = s, this.redirectMethod = i, this.redirectTarget = a, this.iframeNotifyParentOrigin = o, this.iframeScriptOrigin = c, this.silent_redirect_uri = l, this.silentRequestTimeoutInSeconds = p || u || 10, this.automaticSilentRenew = v, this.validateSubOnSilentRenew = w, this.includeIdTokenInSilentRenew = b, this.monitorSession = E, this.monitorAnonymousSession = _, this.checkSessionIntervalInSeconds = m, this.stopCheckSessionOnError = g, this.query_status_response_type = h, this.revokeTokenTypes = $, this.revokeTokensOnSignout = d, this.includeIdTokenInSilentSignout = f, this.accessTokenExpiringNotificationTimeInSeconds = y, k) this.userStore = k;
    else {
      const I = typeof window < "u" ? window.sessionStorage : new Nu();
      this.userStore = new ra({ store: I });
    }
  }
}, ja = class Fu extends Uu {
  constructor({ silentRequestTimeoutInSeconds: e = 10 }) {
    super(), this._logger = new ue("IFrameWindow"), this._timeoutInSeconds = e, this._frame = Fu.createHiddenIframe(), this._window = this._frame.contentWindow;
  }
  static createHiddenIframe() {
    const e = window.document.createElement("iframe");
    return e.style.visibility = "hidden", e.style.position = "fixed", e.style.left = "-1000px", e.style.top = "0", e.width = "0", e.height = "0", window.document.body.appendChild(e), e;
  }
  async navigate(e) {
    this._logger.debug("navigate: Using timeout of:", this._timeoutInSeconds);
    const r = setTimeout(() => {
      this._abort.raise(new ea("IFrame timed out without a response"));
    }, 1e3 * this._timeoutInSeconds);
    return this._disposeHandlers.add(() => clearTimeout(r)), await super.navigate(e);
  }
  close() {
    var e;
    this._frame && (this._frame.parentNode && (this._frame.addEventListener("load", (r) => {
      var n;
      const s = r.target;
      (n = s.parentNode) == null || n.removeChild(s), this._abort.raise(new Error("IFrame removed from DOM"));
    }, !0), (e = this._frame.contentWindow) == null || e.location.replace("about:blank")), this._frame = null), this._window = null;
  }
  static notifyParent(e, r) {
    return super._notifyParent(window.parent, e, !1, r);
  }
}, Rg = class {
  constructor(t) {
    this._settings = t, this._logger = new ue("IFrameNavigator");
  }
  async prepare({ silentRequestTimeoutInSeconds: t = this._settings.silentRequestTimeoutInSeconds }) {
    return new ja({ silentRequestTimeoutInSeconds: t });
  }
  async callback(t) {
    this._logger.create("callback"), ja.notifyParent(t, this._settings.iframeNotifyParentOrigin);
  }
}, za = class extends Uu {
  constructor({ popupWindowTarget: t = Zu, popupWindowFeatures: e = {}, popupSignal: r, popupAbortOnClose: n }) {
    super(), this._logger = new ue("PopupWindow");
    const s = Na.center({ ...Lu, ...e });
    this._window = window.open(void 0, t, Na.serialize(s)), this.abortOnClose = !!n, r && r.addEventListener("abort", () => {
      var i;
      this._abort.raise(new Error((i = r.reason) != null ? i : "Popup aborted"));
    }), e.closePopupWindowAfterInSeconds && e.closePopupWindowAfterInSeconds > 0 && setTimeout(() => {
      this._window && typeof this._window.closed == "boolean" && !this._window.closed ? this.close() : this._abort.raise(new Error("Popup blocked by user"));
    }, 1e3 * e.closePopupWindowAfterInSeconds);
  }
  async navigate(t) {
    var e;
    (e = this._window) == null || e.focus();
    const r = setInterval(() => {
      this._window && !this._window.closed || (this._logger.debug("Popup closed by user or isolated by redirect"), n(), this._disposeHandlers.delete(n), this.abortOnClose && this._abort.raise(new Error("Popup closed by user")));
    }, 500), n = () => clearInterval(r);
    return this._disposeHandlers.add(n), await super.navigate(t);
  }
  close() {
    this._window && (this._window.closed || (this._window.close(), this._abort.raise(new Error("Popup closed")))), this._window = null;
  }
  static notifyOpener(t, e) {
    super._notifyParent(window.opener, t, e), e || window.opener || window.close();
  }
}, Ig = class {
  constructor(t) {
    this._settings = t, this._logger = new ue("PopupNavigator");
  }
  async prepare({ popupWindowFeatures: t = this._settings.popupWindowFeatures, popupWindowTarget: e = this._settings.popupWindowTarget, popupSignal: r, popupAbortOnClose: n }) {
    return new za({ popupWindowFeatures: t, popupWindowTarget: e, popupSignal: r, popupAbortOnClose: n });
  }
  async callback(t, { keepOpen: e = !1 }) {
    this._logger.create("callback"), za.notifyOpener(t, e);
  }
}, Pg = class {
  constructor(t) {
    this._settings = t, this._logger = new ue("RedirectNavigator");
  }
  async prepare({ redirectMethod: t = this._settings.redirectMethod, redirectTarget: e = this._settings.redirectTarget }) {
    var r;
    this._logger.create("prepare");
    let n = window.self;
    e === "top" && (n = (r = window.top) != null ? r : window.self);
    const s = n.location[t].bind(n.location);
    let i;
    return { navigate: async (a) => (this._logger.create("navigate"), await new Promise((c, u) => {
      i = u, window.addEventListener("pageshow", () => c(window.location.href)), s(a.url);
    })), close: () => {
      this._logger.create("close"), i == null || i(new Error("Redirect aborted")), n.stop();
    } };
  }
  async callback() {
  }
}, Og = class extends dg {
  constructor(t) {
    super({ expiringNotificationTimeInSeconds: t.accessTokenExpiringNotificationTimeInSeconds }), this._logger = new ue("UserManagerEvents"), this._userLoaded = new Nt("User loaded"), this._userUnloaded = new Nt("User unloaded"), this._silentRenewError = new Nt("Silent renew error"), this._userSignedIn = new Nt("User signed in"), this._userSignedOut = new Nt("User signed out"), this._userSessionChanged = new Nt("User session changed");
  }
  async load(t, e = !0) {
    await super.load(t), e && await this._userLoaded.raise(t);
  }
  async unload() {
    await super.unload(), await this._userUnloaded.raise();
  }
  addUserLoaded(t) {
    return this._userLoaded.addHandler(t);
  }
  removeUserLoaded(t) {
    return this._userLoaded.removeHandler(t);
  }
  addUserUnloaded(t) {
    return this._userUnloaded.addHandler(t);
  }
  removeUserUnloaded(t) {
    return this._userUnloaded.removeHandler(t);
  }
  addSilentRenewError(t) {
    return this._silentRenewError.addHandler(t);
  }
  removeSilentRenewError(t) {
    return this._silentRenewError.removeHandler(t);
  }
  async _raiseSilentRenewError(t) {
    await this._silentRenewError.raise(t);
  }
  addUserSignedIn(t) {
    return this._userSignedIn.addHandler(t);
  }
  removeUserSignedIn(t) {
    this._userSignedIn.removeHandler(t);
  }
  async _raiseUserSignedIn() {
    await this._userSignedIn.raise();
  }
  addUserSignedOut(t) {
    return this._userSignedOut.addHandler(t);
  }
  removeUserSignedOut(t) {
    this._userSignedOut.removeHandler(t);
  }
  async _raiseUserSignedOut() {
    await this._userSignedOut.raise();
  }
  addUserSessionChanged(t) {
    return this._userSessionChanged.addHandler(t);
  }
  removeUserSessionChanged(t) {
    this._userSessionChanged.removeHandler(t);
  }
  async _raiseUserSessionChanged() {
    await this._userSessionChanged.raise();
  }
}, Cg = class {
  constructor(t) {
    this._userManager = t, this._logger = new ue("SilentRenewService"), this._isStarted = !1, this._retryTimer = new Pt("Retry Silent Renew"), this._tokenExpiring = async () => {
      const e = this._logger.create("_tokenExpiring");
      try {
        await this._userManager.signinSilent(), e.debug("silent token renewal successful");
      } catch (r) {
        if (r instanceof ea) return e.warn("ErrorTimeout from signinSilent:", r, "retry in 5s"), void this._retryTimer.init(5);
        e.error("Error from signinSilent:", r), await this._userManager.events._raiseSilentRenewError(r);
      }
    };
  }
  async start() {
    const t = this._logger.create("start");
    if (!this._isStarted) {
      this._isStarted = !0, this._userManager.events.addAccessTokenExpiring(this._tokenExpiring), this._retryTimer.addHandler(this._tokenExpiring);
      try {
        await this._userManager.getUser();
      } catch (e) {
        t.error("getUser error", e);
      }
    }
  }
  stop() {
    this._isStarted && (this._retryTimer.cancel(), this._retryTimer.removeHandler(this._tokenExpiring), this._userManager.events.removeAccessTokenExpiring(this._tokenExpiring), this._isStarted = !1);
  }
}, Ag = class {
  constructor(t) {
    this.refresh_token = t.refresh_token, this.id_token = t.id_token, this.session_state = t.session_state, this.scope = t.scope, this.profile = t.profile, this.data = t.state;
  }
}, Ng = class {
  constructor(t, e, r, n) {
    this._logger = new ue("UserManager"), this.settings = new Tg(t), this._client = new Sg(t), this._redirectNavigator = e ?? new Pg(this.settings), this._popupNavigator = r ?? new Ig(this.settings), this._iframeNavigator = n ?? new Rg(this.settings), this._events = new Og(this.settings), this._silentRenewService = new Cg(this), this.settings.automaticSilentRenew && this.startSilentRenew(), this._sessionMonitor = null, this.settings.monitorSession && (this._sessionMonitor = new kg(this));
  }
  get events() {
    return this._events;
  }
  get metadataService() {
    return this._client.metadataService;
  }
  async getUser(t = !1) {
    const e = this._logger.create("getUser"), r = await this._loadUser();
    return r ? (e.info("user loaded"), await this._events.load(r, t), r) : (e.info("user not found in storage"), null);
  }
  async removeUser() {
    const t = this._logger.create("removeUser");
    await this.storeUser(null), t.info("user removed from storage"), await this._events.unload();
  }
  async signinRedirect(t = {}) {
    var e;
    this._logger.create("signinRedirect");
    const { redirectMethod: r, ...n } = t;
    let s;
    (e = this.settings.dpop) != null && e.bind_authorization_code && (s = await this.generateDPoPJkt(this.settings.dpop));
    const i = await this._redirectNavigator.prepare({ redirectMethod: r });
    await this._signinStart({ request_type: "si:r", dpopJkt: s, ...n }, i);
  }
  async signinRedirectCallback(t = window.location.href) {
    const e = this._logger.create("signinRedirectCallback"), r = await this._signinEnd(t);
    return r.profile && r.profile.sub ? e.info("success, signed in subject", r.profile.sub) : e.info("no subject"), r;
  }
  async signinResourceOwnerCredentials({ username: t, password: e, skipUserInfo: r = !1 }) {
    const n = this._logger.create("signinResourceOwnerCredential"), s = await this._client.processResourceOwnerPasswordCredentials({ username: t, password: e, skipUserInfo: r, extraTokenParams: this.settings.extraTokenParams });
    n.debug("got signin response");
    const i = await this._buildUser(s);
    return i.profile && i.profile.sub ? n.info("success, signed in subject", i.profile.sub) : n.info("no subject"), i;
  }
  async signinPopup(t = {}) {
    var e;
    const r = this._logger.create("signinPopup");
    let n;
    (e = this.settings.dpop) != null && e.bind_authorization_code && (n = await this.generateDPoPJkt(this.settings.dpop));
    const { popupWindowFeatures: s, popupWindowTarget: i, popupSignal: a, popupAbortOnClose: o, ...c } = t, u = this.settings.popup_redirect_uri;
    u || r.throw(new Error("No popup_redirect_uri configured"));
    const l = await this._popupNavigator.prepare({ popupWindowFeatures: s, popupWindowTarget: i, popupSignal: a, popupAbortOnClose: o }), p = await this._signin({ request_type: "si:p", redirect_uri: u, display: "popup", dpopJkt: n, ...c }, l);
    return p && (p.profile && p.profile.sub ? r.info("success, signed in subject", p.profile.sub) : r.info("no subject")), p;
  }
  async signinPopupCallback(t = window.location.href, e = !1) {
    const r = this._logger.create("signinPopupCallback");
    await this._popupNavigator.callback(t, { keepOpen: e }), r.info("success");
  }
  async signinSilent(t = {}) {
    var e, r;
    const n = this._logger.create("signinSilent"), { silentRequestTimeoutInSeconds: s, ...i } = t;
    let a, o = await this._loadUser();
    if (!t.forceIframeAuth && (o != null && o.refresh_token)) {
      n.debug("using refresh token");
      const p = new Ag(o);
      return await this._useRefreshToken({ state: p, redirect_uri: i.redirect_uri, resource: i.resource, extraTokenParams: i.extraTokenParams, timeoutInSeconds: s });
    }
    (e = this.settings.dpop) != null && e.bind_authorization_code && (a = await this.generateDPoPJkt(this.settings.dpop));
    const c = this.settings.silent_redirect_uri;
    let u;
    c || n.throw(new Error("No silent_redirect_uri configured")), o && this.settings.validateSubOnSilentRenew && (n.debug("subject prior to silent renew:", o.profile.sub), u = o.profile.sub);
    const l = await this._iframeNavigator.prepare({ silentRequestTimeoutInSeconds: s });
    return o = await this._signin({ request_type: "si:s", redirect_uri: c, prompt: "none", id_token_hint: this.settings.includeIdTokenInSilentRenew ? o == null ? void 0 : o.id_token : void 0, dpopJkt: a, ...i }, l, u), o && ((r = o.profile) != null && r.sub ? n.info("success, signed in subject", o.profile.sub) : n.info("no subject")), o;
  }
  async _useRefreshToken(t) {
    const e = await this._client.useRefreshToken({ timeoutInSeconds: this.settings.silentRequestTimeoutInSeconds, ...t }), r = new qn({ ...t.state, ...e });
    return await this.storeUser(r), await this._events.load(r), r;
  }
  async signinSilentCallback(t = window.location.href) {
    const e = this._logger.create("signinSilentCallback");
    await this._iframeNavigator.callback(t), e.info("success");
  }
  async signinCallback(t = window.location.href) {
    const { state: e } = await this._client.readSigninResponseState(t);
    switch (e.request_type) {
      case "si:r":
        return await this.signinRedirectCallback(t);
      case "si:p":
        await this.signinPopupCallback(t);
        break;
      case "si:s":
        await this.signinSilentCallback(t);
        break;
      default:
        throw new Error("invalid response_type in state");
    }
  }
  async signoutCallback(t = window.location.href, e = !1) {
    const { state: r } = await this._client.readSignoutResponseState(t);
    if (r) switch (r.request_type) {
      case "so:r":
        return await this.signoutRedirectCallback(t);
      case "so:p":
        await this.signoutPopupCallback(t, e);
        break;
      case "so:s":
        await this.signoutSilentCallback(t);
        break;
      default:
        throw new Error("invalid response_type in state");
    }
  }
  async querySessionStatus(t = {}) {
    const e = this._logger.create("querySessionStatus"), { silentRequestTimeoutInSeconds: r, ...n } = t, s = this.settings.silent_redirect_uri;
    s || e.throw(new Error("No silent_redirect_uri configured"));
    const i = await this._loadUser(), a = await this._iframeNavigator.prepare({ silentRequestTimeoutInSeconds: r }), o = await this._signinStart({ request_type: "si:s", redirect_uri: s, prompt: "none", id_token_hint: this.settings.includeIdTokenInSilentRenew ? i == null ? void 0 : i.id_token : void 0, response_type: this.settings.query_status_response_type, scope: "openid", skipUserInfo: !0, ...n }, a);
    try {
      const c = {}, u = await this._client.processSigninResponse(o.url, c);
      return e.debug("got signin response"), u.session_state && u.profile.sub ? (e.info("success for subject", u.profile.sub), { session_state: u.session_state, sub: u.profile.sub }) : (e.info("success, user not authenticated"), null);
    } catch (c) {
      if (this.settings.monitorAnonymousSession && c instanceof Bt) switch (c.error) {
        case "login_required":
        case "consent_required":
        case "interaction_required":
        case "account_selection_required":
          return e.info("success for anonymous user"), { session_state: c.session_state };
      }
      throw c;
    }
  }
  async _signin(t, e, r) {
    const n = await this._signinStart(t, e);
    return await this._signinEnd(n.url, r);
  }
  async _signinStart(t, e) {
    const r = this._logger.create("_signinStart");
    try {
      const n = await this._client.createSigninRequest(t);
      return r.debug("got signin request"), await e.navigate({ url: n.url, state: n.state.id, response_mode: n.state.response_mode, scriptOrigin: this.settings.iframeScriptOrigin });
    } catch (n) {
      throw r.debug("error after preparing navigator, closing navigator window"), e.close(), n;
    }
  }
  async _signinEnd(t, e) {
    const r = this._logger.create("_signinEnd"), n = await this._client.processSigninResponse(t, {});
    return r.debug("got signin response"), await this._buildUser(n, e);
  }
  async _buildUser(t, e) {
    const r = this._logger.create("_buildUser"), n = new qn(t);
    if (e) {
      if (e !== n.profile.sub) throw r.debug("current user does not match user returned from signin. sub from signin:", n.profile.sub), new Bt({ ...t, error: "login_required" });
      r.debug("current user matches user returned from signin");
    }
    return await this.storeUser(n), r.debug("user stored"), await this._events.load(n), n;
  }
  async signoutRedirect(t = {}) {
    const e = this._logger.create("signoutRedirect"), { redirectMethod: r, ...n } = t, s = await this._redirectNavigator.prepare({ redirectMethod: r });
    await this._signoutStart({ request_type: "so:r", post_logout_redirect_uri: this.settings.post_logout_redirect_uri, ...n }, s), e.info("success");
  }
  async signoutRedirectCallback(t = window.location.href) {
    const e = this._logger.create("signoutRedirectCallback"), r = await this._signoutEnd(t);
    return e.info("success"), r;
  }
  async signoutPopup(t = {}) {
    const e = this._logger.create("signoutPopup"), { popupWindowFeatures: r, popupWindowTarget: n, popupSignal: s, ...i } = t, a = this.settings.popup_post_logout_redirect_uri, o = await this._popupNavigator.prepare({ popupWindowFeatures: r, popupWindowTarget: n, popupSignal: s });
    await this._signout({ request_type: "so:p", post_logout_redirect_uri: a, state: a == null ? void 0 : {}, ...i }, o), e.info("success");
  }
  async signoutPopupCallback(t = window.location.href, e = !1) {
    const r = this._logger.create("signoutPopupCallback");
    await this._popupNavigator.callback(t, { keepOpen: e }), r.info("success");
  }
  async _signout(t, e) {
    const r = await this._signoutStart(t, e);
    return await this._signoutEnd(r.url);
  }
  async _signoutStart(t = {}, e) {
    var r;
    const n = this._logger.create("_signoutStart");
    try {
      const s = await this._loadUser();
      n.debug("loaded current user from storage"), this.settings.revokeTokensOnSignout && await this._revokeInternal(s);
      const i = t.id_token_hint || s && s.id_token;
      i && (n.debug("setting id_token_hint in signout request"), t.id_token_hint = i), await this.removeUser(), n.debug("user removed, creating signout request");
      const a = await this._client.createSignoutRequest(t);
      return n.debug("got signout request"), await e.navigate({ url: a.url, state: (r = a.state) == null ? void 0 : r.id, scriptOrigin: this.settings.iframeScriptOrigin });
    } catch (s) {
      throw n.debug("error after preparing navigator, closing navigator window"), e.close(), s;
    }
  }
  async _signoutEnd(t) {
    const e = this._logger.create("_signoutEnd"), r = await this._client.processSignoutResponse(t);
    return e.debug("got signout response"), r;
  }
  async signoutSilent(t = {}) {
    var e;
    const r = this._logger.create("signoutSilent"), { silentRequestTimeoutInSeconds: n, ...s } = t, i = this.settings.includeIdTokenInSilentSignout ? (e = await this._loadUser()) == null ? void 0 : e.id_token : void 0, a = this.settings.popup_post_logout_redirect_uri, o = await this._iframeNavigator.prepare({ silentRequestTimeoutInSeconds: n });
    await this._signout({ request_type: "so:s", post_logout_redirect_uri: a, id_token_hint: i, ...s }, o), r.info("success");
  }
  async signoutSilentCallback(t = window.location.href) {
    const e = this._logger.create("signoutSilentCallback");
    await this._iframeNavigator.callback(t), e.info("success");
  }
  async revokeTokens(t) {
    const e = await this._loadUser();
    await this._revokeInternal(e, t);
  }
  async _revokeInternal(t, e = this.settings.revokeTokenTypes) {
    const r = this._logger.create("_revokeInternal");
    if (!t) return;
    const n = e.filter((s) => typeof t[s] == "string");
    if (n.length) {
      for (const s of n) await this._client.revokeToken(t[s], s), r.info(`${s} revoked successfully`), s !== "access_token" && (t[s] = null);
      await this.storeUser(t), r.debug("user stored"), await this._events.load(t);
    } else r.debug("no need to revoke due to no token(s)");
  }
  startSilentRenew() {
    this._logger.create("startSilentRenew"), this._silentRenewService.start();
  }
  stopSilentRenew() {
    this._silentRenewService.stop();
  }
  get _userStoreKey() {
    return `user:${this.settings.authority}:${this.settings.client_id}`;
  }
  async _loadUser() {
    const t = this._logger.create("_loadUser"), e = await this.settings.userStore.get(this._userStoreKey);
    return e ? (t.debug("user storageString loaded"), qn.fromStorageString(e)) : (t.debug("no user storageString"), null);
  }
  async storeUser(t) {
    const e = this._logger.create("storeUser");
    if (t) {
      e.debug("storing user");
      const r = t.toStorageString();
      await this.settings.userStore.set(this._userStoreKey, r);
    } else this._logger.debug("removing user"), await this.settings.userStore.remove(this._userStoreKey), this.settings.dpop && await this.settings.dpop.store.remove(this.settings.client_id);
  }
  async clearStaleState() {
    await this._client.clearStaleState();
  }
  async dpopProof(t, e, r, n) {
    var s, i;
    const a = await ((i = (s = this.settings.dpop) == null ? void 0 : s.store) == null ? void 0 : i.get(this.settings.client_id));
    if (a) return await qe.generateDPoPProof({ url: t, accessToken: e == null ? void 0 : e.access_token, httpMethod: r, keyPair: a.keys, nonce: n });
  }
  async generateDPoPJkt(t) {
    let e = await t.store.get(this.settings.client_id);
    if (!e) {
      const r = await qe.generateDPoPKeys();
      e = new Mu(r), await t.store.set(this.settings.client_id, e);
    }
    return await qe.generateDPoPJkt(e.keys);
  }
};
const Hu = "OAUTH2_LOGIN_FLOW_COMPLETE_EVENT", Vu = "OAUTH_GET_TOP_URL", li = "OAUTH_REDIRECT_TOP_WINDOW", Ku = "OAUTH_UPDATE_URL", Gu = "OAUTH2_CHECK_PENDING", Jn = "oauth2_top_origin", Cr = "oauth2_login_success", Bn = "oauth2_state", di = 60, xg = Math.max(di - 15, 20), jg = Xi("oidc-auth", { color: "green" }), hs = (t) => jg.extend(t);
Xi("oidc-auth-utils");
const qa = () => typeof window > "u" ? "" : new URLSearchParams(window.location.search).get("origin") || "", Kt = class Kt {
  constructor() {
    ve(this, "settings", null);
  }
  static getInstance() {
    return Kt.instance || (Kt.instance = new Kt()), Kt.instance;
  }
  configure(e) {
    this.settings = e;
  }
  isConfigured() {
    return this.settings !== null;
  }
  getSettings() {
    if (!this.settings) throw new Error("OidcAuthConfig not configured. Call configure() or pass settings to OidcAuthClient.initialize().");
    return this.settings;
  }
  getAuthOrigin() {
    const { authOrigin: e, authEndpoint: r } = this.getSettings();
    return e || new URL(r).origin;
  }
  isAccessTokenProactiveRefreshEnabled() {
    var e;
    return ((e = this.settings) == null ? void 0 : e.accessTokenProactiveRefreshEnabled) ?? !0;
  }
  getOidcSettings() {
    const e = typeof window > "u" ? "" : window.location.origin, { clientId: r, authEndpoint: n } = this.getSettings(), s = this.getAuthOrigin(), i = typeof window < "u" ? new ra({ store: window.localStorage }) : void 0, { accessTokenExpiringNotificationTimeInSeconds: a = di } = this.getSettings();
    return { client_id: r, authority: s, redirect_uri: `${e}/login/oauth-callback`, post_logout_redirect_uri: e, response_type: "code", scope: "openid offline_access", automaticSilentRenew: !1, accessTokenExpiringNotificationTimeInSeconds: a, stateStore: i, userStore: i, metadata: { issuer: s, authorization_endpoint: n, token_endpoint: `${s}/connect/api/v1/oauth2/token`, end_session_endpoint: `${s}/logout/` } };
  }
  getAccessTokenExpiringNotificationTimeInSeconds() {
    return this.getSettings().accessTokenExpiringNotificationTimeInSeconds ?? di;
  }
  getAccessTokenFreshnessThresholdInSeconds() {
    return this.getSettings().accessTokenFreshnessThresholdInSeconds ?? xg;
  }
  getAllowedParentOrigins() {
    var e;
    return (e = this.settings) == null ? void 0 : e.allowedParentOrigins;
  }
};
ve(Kt, "instance", null);
let hi = Kt;
const gt = hi.getInstance(), zg = hs("oidc-auth:host-api"), gr = async (t) => new Promise((e, r) => {
  const n = new MessageChannel();
  let s = !1;
  const i = () => {
    s = !0, n.port1.close();
  }, a = setTimeout(() => {
    s || (i(), r(new Error(`Host message timeout: ${t.type}`)));
  }, 1e4);
  n.port1.onmessage = (c) => {
    clearTimeout(a), i(), c.data.status !== "success" ? r(c.data.payload) : e(c.data.payload);
  };
  const o = new URLSearchParams(window.location.search).get("origin") || "";
  if (!(function(c) {
    if (!c.startsWith("http://") && !c.startsWith("https://")) return !1;
    const u = gt.getAllowedParentOrigins();
    return !u || u.length === 0 || u.includes(c);
  })(o)) return clearTimeout(a), i(), void r(new Error("Origin not allowed"));
  zg.log("posting message to host", t), window.top.postMessage({ type: t.type, payload: t.payload, ...t.data || {} }, o, [n.port2]);
}), qg = hs("oidc-auth:OidcAuthTimer");
class Mg {
  constructor() {
    ve(this, "timerHandle", null);
    ve(this, "expiration", null);
    ve(this, "initialized", !1);
    ve(this, "callback", () => {
    });
    this.timerHandle = null;
  }
  init(e, r, n) {
    const s = e - this.getEpochTime(), i = Math.max(s - r, 10);
    this.cancel(), this.expiration = i, this.callback = n, qg.debug("OIDC: timer - using expiration", i, s, r, e, s - r), this.timerHandle = setTimeout(this.callback, 1e3 * i), this.initialized = !0;
  }
  cancel() {
    this.timerHandle && (clearTimeout(this.timerHandle), this.timerHandle = null), this.expiration = null;
  }
  getEpochTime() {
    return Math.floor(Date.now() / 1e3);
  }
  isInitialized() {
    return this.initialized;
  }
}
const me = hs("oidc-auth:OidcAuthClient"), Gt = class Gt {
  constructor() {
    ve(this, "userManager", null);
    ve(this, "initialized", !1);
    ve(this, "accessTokenExpiringTimer", null);
    ve(this, "retryTimers", /* @__PURE__ */ new Set());
  }
  static getInstance() {
    return Gt.instance || (Gt.instance = new Gt()), Gt.instance;
  }
  isInitialized() {
    return this.initialized;
  }
  ensureInitialized() {
    if (!this.userManager) throw new Error("OidcAuthClient not initialized. Call initialize() first.");
    return this.userManager;
  }
  initialize(e) {
    if (e && (this.initialized = !1, gt.configure(e)), this.initialized) me.info("OIDC: initialize() - already initialized, skipping");
    else if (typeof window < "u") if (gt.isConfigured()) try {
      me.info("OIDC: initialize() - starting initialization");
      const r = gt.getOidcSettings();
      this.userManager = new Ng(r), Jt.setLogger(me), Jt.setLevel(Jt.ERROR), this.initAccessTokenExpiringTimer(), this.initialized = !0;
    } catch (r) {
      throw me.error("OIDC: initialize() - FAILED:", r), r;
    }
    else me.warn("OIDC: initialize() - skipped, config not set");
    else me.warn("OidcAuthClient cannot initialize on server side");
  }
  async initAccessTokenExpiringTimer() {
    gt.isAccessTokenProactiveRefreshEnabled() ? this.getUser().then((e) => {
      const r = e == null ? void 0 : e.expires_at;
      r && (this.accessTokenExpiringTimer || (this.accessTokenExpiringTimer = new Mg()), this.accessTokenExpiringTimer.init(r, gt.getAccessTokenExpiringNotificationTimeInSeconds(), async () => {
        me.info("OIDC: timer proactive refresh access token expiring timer fired", r), this.proactiveRefreshWithRetry();
      }));
    }).catch((e) => {
      me.error("OIDC: initAccessTokenExpiringTimer - FAILED:", e);
    }) : me.warn("OIDC: timer - not starting, access token proactive refresh is disabled");
  }
  async getUser() {
    if (!this.userManager) return null;
    try {
      return await this.userManager.getUser();
    } catch (e) {
      return me.error("OIDC: getUser - FAILED:", e), null;
    }
  }
  async storeUser(e) {
    await this.ensureInitialized().storeUser(e);
  }
  async getAccessToken() {
    const e = await this.getUser();
    if (!e) return me.info("OIDC: getAccessToken - no user found"), null;
    if (e.expired) try {
      const r = await this.signinSilent();
      return (r == null ? void 0 : r.access_token) || null;
    } catch (r) {
      return me.error("OIDC: getAccessToken - silent renew failed:", r), null;
    }
    return this.isTokenFresh(e) || this.signinSilent().catch((r) => {
      me.error("OIDC: getAccessToken - background refresh failed:", r);
    }), e.access_token;
  }
  getUserData() {
    if (typeof window > "u") return null;
    try {
      const e = gt.getOidcSettings(), r = `oidc.user:${e.authority}:${e.client_id}`, n = localStorage.getItem(r);
      if (!n) return null;
      const s = JSON.parse(n), i = s == null ? void 0 : s.profile;
      return i != null && i.sub ? (me.info("OIDC: USER:", { profile: i }), { id: i.sub, email: i.email || "", first_name: i.given_name, last_name: i.family_name }) : null;
    } catch (e) {
      return me.error("OIDC: getUserData - FAILED:", e), null;
    }
  }
  async isAuthenticated() {
    const e = await this.getUser();
    return e !== null && !e.expired;
  }
  async signinRedirect(e) {
    await this.ensureInitialized().signinRedirect({ state: e ? { data: e } : void 0, prompt: "login" });
  }
  async signinCallback() {
    const e = this.ensureInitialized(), r = await e.signinCallback();
    if (!r) throw me.error("OIDC: signinCallback - FAILED: no user returned"), new Error("Signin callback failed: no user returned");
    return r;
  }
  async signinSilent(e) {
    return this.ensureInitialized(), typeof navigator < "u" && navigator.locks ? navigator.locks.request("oidc-token-refresh", async () => {
      const r = await this.getUser();
      return r && this.isTokenFresh(r, e) ? r : this.doSigninSilent();
    }) : (me.warn("OIDC: signinSilent - navigator.locks not available, proceeding without lock"), this.doSigninSilent());
  }
  isTokenFresh(e, r) {
    if (!e.expires_at) return !1;
    const n = r ?? gt.getAccessTokenFreshnessThresholdInSeconds(), s = Math.floor(Date.now() / 1e3);
    return e.expires_at - s > n;
  }
  async doSigninSilent() {
    const e = this.ensureInitialized();
    try {
      return await e.signinSilent();
    } catch (r) {
      throw me.error("OIDC: doSigninSilent - FAILED:", r), r;
    }
  }
  proactiveRefreshWithRetry(e = 1) {
    if (typeof document < "u" && document.visibilityState === "hidden") {
      me.info("OIDC: tab is hidden, deferring proactive refresh until visible");
      const r = () => {
        document.visibilityState === "visible" && (document.removeEventListener("visibilitychange", r), this.proactiveRefreshWithRetry(e));
      };
      return void document.addEventListener("visibilitychange", r);
    }
    this.signinSilent(gt.getAccessTokenExpiringNotificationTimeInSeconds()).then(() => {
      this.initAccessTokenExpiringTimer();
    }).catch((r) => {
      if (me.error(`OIDC: proactive refresh failed (attempt ${e}/2):`, r), e < 2) {
        const n = setTimeout(() => {
          this.retryTimers.delete(n), this.proactiveRefreshWithRetry(e + 1);
        }, 3e3);
        this.retryTimers.add(n);
      } else me.error("OIDC: proactive refresh exhausted all retries");
    });
  }
  async removeUser() {
    var r;
    const e = this.ensureInitialized();
    (r = this.accessTokenExpiringTimer) == null || r.cancel(), this.retryTimers.forEach(clearTimeout), this.retryTimers.clear(), await e.removeUser();
  }
  onUserLoaded(e) {
    this.ensureInitialized().events.addUserLoaded(e);
  }
  offUserLoaded(e) {
    this.ensureInitialized().events.removeUserLoaded(e);
  }
  onUserUnloaded(e) {
    this.ensureInitialized().events.addUserUnloaded(e);
  }
  offUserUnloaded(e) {
    this.ensureInitialized().events.removeUserUnloaded(e);
  }
  onSilentRenewError(e) {
    this.ensureInitialized().events.addSilentRenewError(e);
  }
  offSilentRenewError(e) {
    this.ensureInitialized().events.removeSilentRenewError(e);
  }
  onAccessTokenExpiring(e) {
    this.ensureInitialized().events.addAccessTokenExpiring(e);
  }
  offAccessTokenExpiring(e) {
    this.ensureInitialized().events.removeAccessTokenExpiring(e);
  }
  onAccessTokenExpired(e) {
    this.ensureInitialized().events.addAccessTokenExpired(e);
  }
  offAccessTokenExpired(e) {
    this.ensureInitialized().events.removeAccessTokenExpired(e);
  }
  getLogoutUrl(e, r) {
    const n = new URL((function(s) {
      return `${gt.getAuthOrigin()}${s.logoutPath}`;
    })(e));
    return r && n.searchParams.set("redirect_to", r), n.toString();
  }
  getWindowOriginParam() {
    const e = new URL(window.location.href).searchParams.get("origin");
    if (!e) throw new Error("iframe origin param is required");
    return e;
  }
  async getTopUrl() {
    return (await gr({ type: Vu })).topUrl;
  }
  async isOAuthFlowPending() {
    try {
      return (await gr({ type: Gu })).isPending;
    } catch (e) {
      return me.warn("OIDC: isOAuthFlowPending() - failed to check, assuming not pending:", e), !1;
    }
  }
  async triggerLoginFlowViaParent({ loginPath: e, windowPath: r }) {
    me.info("OIDC: triggerLoginFlowViaParent() - starting");
    const n = await this.getTopUrl(), s = new URL(n).origin, i = `${s}${r}`, a = new URL(`${window.location.origin}${e}`);
    a.searchParams.set(Jn, s), a.searchParams.set("oauth2_top_wp_url", i), me.info("OIDC: triggerLoginFlowViaParent() - redirecting parent to:", a.toString()), await gr({ type: li, payload: { url: a.toString() } });
  }
  async handleLoginFlowComplete(e, r) {
    var a;
    if (!r) throw new Error("oauthUserState is required");
    const n = this.getWindowOriginParam(), s = r.state, i = (a = s == null ? void 0 : s.data) == null ? void 0 : a[Jn];
    if (n !== i) throw me.error("OIDC: handleLoginFlowComplete - origin mismatch:", n, "!==", i), new Error("Invalid origin in OAuth state");
    try {
      const o = new qn(r);
      await this.storeUser(o), this.initAccessTokenExpiringTimer(), window.dispatchEvent(new CustomEvent("oidc-auth-completed"));
    } catch (o) {
      me.error("OIDC: handleLoginFlowComplete - FAILED to store user:", o), await this.triggerLoginFlowViaParent(e);
    }
  }
  async triggerLogoutViaParent(e, r = !0) {
    const n = await this.getTopUrl(), s = new URL(n).origin, i = r ? `${s}${e.windowPath}` : s;
    await this.removeUser();
    const a = this.getLogoutUrl(e, i);
    await gr({ type: li, payload: { url: a } });
  }
  async cleanOAuthParamsFromUrl() {
    try {
      const e = await this.getTopUrl(), r = new URL(e);
      r.searchParams.delete("oauth_code"), r.searchParams.delete("oauth_state"), r.searchParams.delete("start-oauth"), r.searchParams.delete(Cr), r.searchParams.delete(Bn), await gr({ type: Ku, payload: { url: r.toString() } });
    } catch (e) {
      me.warn("Failed to clean OAuth params from URL:", e);
    }
  }
  setupLoginFlowMessageListener(e) {
    let r = !1;
    const n = (s) => {
      var a;
      if (((a = s.data) == null ? void 0 : a.type) !== Hu) return;
      if (s.origin !== qa()) return void me.error("OIDC: origin mismatch - expected:", qa(), "received:", s.origin);
      if (r) return void me.debug("OIDC: LOGIN_FLOW_COMPLETE already processed, ignoring duplicate");
      const i = s.data.payload;
      i != null && i.oauthState ? (r = !0, this.handleLoginFlowComplete(e, i.oauthState).catch((o) => {
        me.error("OIDC: Failed to handle login flow complete:", o), r = !1;
      })) : me.warn("OIDC: LOGIN_FLOW_COMPLETE but no oauthState in payload");
    };
    return window.addEventListener("message", n), () => {
      window.removeEventListener("message", n);
    };
  }
  async getTokenExpirationInfo() {
    const e = await this.getUser();
    if (!e || !e.expires_at) return { expiresAt: null, expiresInSeconds: null, isExpired: !0 };
    const r = new Date(1e3 * e.expires_at), n = Date.now(), s = Math.floor((1e3 * e.expires_at - n) / 1e3);
    return { expiresAt: r, expiresInSeconds: s, isExpired: s <= 0 };
  }
  async forceTokenRefresh() {
    return me.info("OIDC: forceTokenRefresh() - manually triggering token refresh"), this.signinSilent();
  }
};
ve(Gt, "instance", null);
let fi = Gt;
const Dg = fi.getInstance();
typeof window < "u" && (window.oidcAuthClient = Dg);
const Ut = hs("oidc-auth:oidc-auth-redirect");
function Es(t, e) {
  t.postMessage({ status: "success", payload: e });
}
function Ma(t, e) {
  t.postMessage({ status: "error", payload: e });
}
function pi({ targets: t, onSuccess: e, attempt: r = 1 }) {
  var i, a, o;
  const n = new URLSearchParams(window.location.search);
  if (!n.get(Cr)) return void Ut.warn("OIDC: No login_success param found, skipping");
  const s = n.get(Bn);
  if (s) {
    if (!((i = t.window) != null && i.contentWindow) || !t.windowURL) return Ut.warn("Cannot forward OIDC state: iframe not available"), void (r < 5 ? setTimeout(() => {
      pi({ targets: t, onSuccess: e, attempt: r + 1 });
    }, 500) : Ut.error("OIDC: Failed to forward login flow after", 5, "attempts - iframe never became available"));
    try {
      const c = JSON.parse(s), u = (o = (a = c.state) == null ? void 0 : a.data) == null ? void 0 : o[Jn];
      if (u && u !== window.location.origin) return void Ut.error("Origin mismatch in OIDC state:", u, "vs", window.location.origin);
      (function(p, v) {
        var E, _;
        const w = (E = v.window) == null ? void 0 : E.contentWindow, b = (_ = v.windowURL) == null ? void 0 : _.origin;
        w && b ? w.postMessage({ type: Hu, payload: p }, b) : Ut.warn("Cannot send OIDC state: window or origin not available");
      })({ oauthState: c }, t);
      const l = new URL(window.location.href);
      l.searchParams.delete(Cr), l.searchParams.delete(Bn), history.replaceState({}, "", l.toString()), e == null || e();
    } catch (c) {
      Ut.error("Failed to parse or forward OIDC state:", c);
    }
  } else Ut.warn("OIDC login complete but no state found in URL");
}
const Wu = "angie_return_url", $r = St("referrer-redirect");
function Ug(t) {
  try {
    return new URL(t, window.location.origin).origin === window.location.origin;
  } catch {
    return !1;
  }
}
function Ju() {
  try {
    const t = localStorage.getItem(Wu);
    if (!t) return null;
    let e;
    try {
      e = JSON.parse(t);
    } catch {
      return $r.warn("Stored redirect data is not valid JSON, returning null"), null;
    }
    return e.url && typeof e.url == "string" ? Ug(e.url) ? e : ($r.warn("Stored redirect URL is invalid, returning null:", e.url), null) : ($r.warn("Stored redirect data missing url field, returning null"), null);
  } catch {
    return $r.warn("localStorage not available"), null;
  }
}
function Bu() {
  try {
    localStorage.removeItem(Wu);
  } catch {
    $r.warn("localStorage not available");
  }
}
function Qu(t, e) {
  return e ? `${t}#angie-prompt=${encodeURIComponent(e)}` : t;
}
function Lg() {
  const t = Ju();
  return !!t && (Bu(), window.location.href = Qu(t.url, t.prompt), !0);
}
const mi = St("oauth");
function Ts() {
  const t = Ju();
  if (t) return Bu(), void (window.location.href = Qu(t.url, t.prompt));
  try {
    localStorage.setItem("angie_sidebar_state", "open");
  } catch {
    mi.warn("localStorage not available");
  }
  setTimeout(() => {
    window.toggleAngieSidebar(!0);
  }, 500);
}
const Da = (t, e) => {
  const r = document.getElementById(Ne.containerId);
  r && r.setAttribute("aria-hidden", e ? "false" : "true"), e ? t.removeAttribute("tabindex") : t.setAttribute("tabindex", "-1");
}, Ua = (t, e) => {
  t.postMessage({ status: "success", payload: e });
}, Jr = St("sdk");
var La;
(La || (La = {})).POST_MESSAGE = "postMessage";
const na = St("sidebar");
let Rs = !1;
const Zg = "open", Fg = "closed";
function Hg() {
  if (typeof window > "u") return 370;
  try {
    const t = window.localStorage.getItem("angie_sidebar_width");
    if (t) {
      const e = parseInt(t, 10);
      if (e >= 350 && e <= 590) return e;
    }
  } catch {
    na.warn("localStorage not available");
  }
  return 370;
}
function Vg(t) {
  try {
    localStorage.setItem("angie_sidebar_state", t);
  } catch {
    na.warn("localStorage not available");
  }
}
function Kg(t) {
  var e;
  t != null && t.skipDefaultCss || (function() {
    if (typeof document > "u" || Rs) return;
    const r = "angie-sidebar-styles";
    if (document.getElementById(r)) return void (Rs = !0);
    const n = document.createElement("style");
    n.id = r, n.textContent = `/* Angie Sidebar - CSS Variables */
:root {
    --angie-sidebar-z-index: 1200; /* below MUI popups, elementor popups and media library modal */
    --angie-sidebar-width: 330px;
    --angie-sidebar-transition: margin 0.3s ease-in-out, transform 0.3s ease-in-out;
    /* Direction-aware transform values for sidebar positioning */
    --angie-sidebar-hide-transform: translateX(-100%); /* LTR: hide to the left */
    --angie-sidebar-show-transform: translateX(0);
}

/* RTL-specific transform values */
[dir="rtl"] {
    --angie-sidebar-hide-transform: translateX(100%); /* RTL: hide to the right */
}

/* Respect user's motion preferences */
@media (prefers-reduced-motion: reduce) {
    :root {
        --angie-sidebar-transition: none;
    }
}

/* Apply transitions only when user is actively toggling */
body.angie-sidebar-transitioning {
    transition: var(--angie-sidebar-transition) !important;
}

body.angie-sidebar-transitioning #angie-sidebar-container {
    transition: var(--angie-sidebar-transition) !important;
}

/* Layout (default) - Push content */
@media (min-width: 768px) {
    body.angie-sidebar-active {
        padding-inline-start: var(--angie-sidebar-width) !important;
    }

    #angie-sidebar-container {
        position: fixed;
        top: 0;
        inset-inline-start: 0;
        width: var(--angie-sidebar-width);
        height: 100vh;
        z-index: var(--angie-sidebar-z-index) !important; /* below elementor popups and media library modal */
        background: #FCFCFC;
        transform: var(--angie-sidebar-hide-transform);
        outline: none;
        overflow: hidden;
        /* No default transition - only when transitioning */
    }

    /* Resize handle */
    #angie-sidebar-container::after {
        content: '';
        position: absolute;
        top: 0;
        inset-inline-end: 0;
        width: 4px;
        height: 100%;
        cursor: ew-resize;
        background: transparent;
        z-index: 1000001;
    }

    /* Pink border during resize */
    #angie-sidebar-container.angie-resizing {
        border-inline-end-color: #ff69b4 !important;
        border-inline-end-width: 2px !important;
    }

    /* Disable iframe pointer events during resize */
    #angie-sidebar-container.angie-resizing iframe#angie-iframe {
        pointer-events: none !important;
    }
}

/* Active states */
body.angie-sidebar-active #angie-sidebar-container {
    transform: var(--angie-sidebar-show-transform);
}

/* Studio mode - sidebar takes full width */
@media (min-width: 768px) {
    html.angie-studio-active body.angie-sidebar-active #angie-sidebar-container {
        width: 100%;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    #angie-sidebar-container {
        border-color: #000;
        box-shadow: none;
    }
}

/* Screen reader only class */
.angie-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Plugin conflict resolution */
body.angie-sidebar-active {
    /* Reset common conflicting styles */
    box-sizing: border-box !important;
    position: relative !important;
}

#angie-sidebar-toggle {
    z-index: 99999 !important;
}
`;
    const s = document.head || document.getElementsByTagName("head")[0];
    s.insertBefore(n, s.firstChild), Rs = !0;
  })(), typeof window < "u" && (window.toggleAngieSidebar = (e = t == null ? void 0 : t.onToggle, function(r, n) {
    const s = document.body, i = document.getElementById(Ne.containerId);
    if (!i) return void na.warn("Required elements not found!");
    const a = s.classList.contains("angie-sidebar-active"), o = r !== void 0 ? r : !a;
    n || (s.classList.add("angie-sidebar-transitioning"), setTimeout(function() {
      s.classList.remove("angie-sidebar-transitioning");
    }, 300)), o ? s.classList.add("angie-sidebar-active") : s.classList.remove("angie-sidebar-active"), o && setTimeout(function() {
      ni({ type: "focusInput" });
    }, n ? 0 : 300), e && e(o, i, n), Vg(o ? Zg : Fg);
    const c = new CustomEvent("angieSidebarToggle", { detail: { isOpen: o, sidebar: i, skipTransition: n } });
    document.dispatchEvent(c), ni({ type: xe.ANGIE_SIDEBAR_TOGGLED, payload: { state: o ? "opened" : "closed" } });
  }), window.addEventListener("message", function(r) {
    if (r.data && r.data.type === "toggleAngieSidebar") {
      const { force: n, skipTransition: s } = r.data.payload || {};
      window.toggleAngieSidebar && window.toggleAngieSidebar(n, s);
    }
  }));
}
const it = St("iframe"), Gg = (t) => {
  if (t.includes("://") || t.startsWith("//")) return !1;
  try {
    const e = "https://test.com";
    return new URL(t, e).origin === e;
  } catch {
    return !1;
  }
}, Za = async () => {
  var t;
  if ((t = Ne.iframe) != null && t.contentWindow && Ne.iframeUrlObject) try {
    it.log("Disabling navigation prevention in Angie iframe"), Ne.iframe.contentWindow.postMessage({ type: xe.ANGIE_DISABLE_NAVIGATION_PREVENTION }, Ne.iframeUrlObject.origin), await new Promise((e) => setTimeout(e, 100));
  } catch (e) {
    throw it.error("Failed to disable navigation prevention:", e), e;
  }
  else it.warn("Cannot disable navigation prevention: iframe or origin not available");
}, Wg = async (t) => {
  var s;
  if (window.screen.availWidth <= 768) return void it.log("Mobile detected, skipping iframe injection");
  let e = document.getElementById(Ne.containerId);
  if (!e) {
    const i = performance.now();
    if (it.log("⏱️ Waiting for sidebar container..."), await new Promise((a) => {
      let o = 0;
      const c = setInterval(() => {
        e = document.getElementById(Ne.containerId), o++, (e || o > 20) && (clearInterval(c), e && a());
      }, 100);
      setTimeout(() => {
        if (clearInterval(c), e) return void a();
        const u = new MutationObserver(() => {
          e = document.getElementById(Ne.containerId), e && (u.disconnect(), a());
        });
        u.observe(document.body, { childList: !0, subtree: !0 }), setTimeout(() => {
          u.disconnect(), a();
        }, 8e3);
      }, 2e3);
    }), it.log(`⏱️ Sidebar container detection took: ${(performance.now() - i).toFixed(2)}ms`), !e) return void it.error("Sidebar container not found");
  }
  const { iframe: r, iframeUrlObject: n } = await (async (i) => {
    const a = i.origin, o = new URL(i.path, a), c = o.pathname.slice(1).replace(/\//, "--") + "-" + Math.random().toString(36).substring(7);
    return new Promise((u) => {
      const l = new URL(a);
      l.pathname = o.pathname;
      const p = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
      if (l.searchParams.append("colorScheme", i.uiTheme || p || "light"), l.searchParams.append("sdkVersion", i.sdkVersion), l.searchParams.append("instanceId", c), l.searchParams.append("origin", window.location.origin), i.isRTL && l.searchParams.append("isRTL", i.isRTL ? "true" : "false"), window.location.hostname === "localhost" && window.location.search.includes("debug_error")) {
        const E = new URLSearchParams(window.location.search).get("debug_error");
        E && l.searchParams.append("debug_error", E);
      }
      o.searchParams.forEach((E, _) => {
        l.searchParams.set(_, E);
      }), l.searchParams.set("ver", (/* @__PURE__ */ new Date()).getTime().toString());
      const v = i.parent || document, w = v.createElement("iframe"), b = { "background-color": "transparent", "color-scheme": "normal", ...i.css };
      window.addEventListener("message", async (E) => {
        var _;
        if (E.origin === l.origin) switch (E.data.type) {
          case nr.ANGIE_READY:
            u({ iframe: w, iframeUrlObject: l });
            break;
          case nr.ANGIE_LOADED:
            (_ = w.contentWindow) == null || _.postMessage({ type: nr.HOST_READY, instanceId: c }, l.origin);
        }
      }), w.setAttribute("src", l.href), w.id = "angie-iframe", w.setAttribute("frameborder", "0"), w.setAttribute("scrolling", "no"), w.setAttribute("style", Object.entries(b).map(([E, _]) => `${E}: ${_}`).join("; ")), w.setAttribute("allow", "clipboard-write; clipboard-read"), i.insertCallback ? i.insertCallback(w) : v.body.appendChild(w);
    });
  })({ origin: t.origin || "https://angie.elementor.com", path: t.path && Gg(t.path) ? t.path : "angie/wp-admin", insertCallback: (i) => {
    it.log("Injecting Angie iframe into sidebar container"), i.setAttribute("title", "Angie AI Assistant"), i.setAttribute("role", "application"), i.setAttribute("aria-label", "Angie AI Assistant Interface");
    const a = document.getElementById("angie-sidebar-loading");
    a && (a.textContent = ""), e == null || e.appendChild(i), Da(i, !0), i.addEventListener("load", () => {
      i.focus();
    });
  }, css: { width: "100%", height: "100%", border: "none", outline: "none" }, uiTheme: t.uiTheme, isRTL: t.isRTL, sdkVersion: "1.4.8" });
  Ne.iframe = r, Ne.iframeUrlObject = n, window.addEventListener("message", (i) => {
    var a;
    if (i.origin === ((a = Ne.iframeUrlObject) == null ? void 0 : a.origin)) switch (i.data.type) {
      case Gn.SET:
        window.localStorage.setItem(i.data.key, i.data.value);
        break;
      case Gn.GET: {
        const o = i.ports[0], c = window.localStorage.getItem(i.data.key);
        o.postMessage({ value: c });
        break;
      }
    }
  }), ((i) => {
    window.addEventListener("message", async (a) => {
      var u, l, p, v, w, b, E;
      const o = a.origin === window.location.origin, c = a.origin === ((u = i.iframeUrlObject) == null ? void 0 : u.origin);
      if (o || c) switch ((l = a == null ? void 0 : a.data) == null ? void 0 : l.type) {
        case xe.SDK_ANGIE_ALL_SERVERS_REGISTERED:
          break;
        case xe.SDK_ANGIE_READY_PING: {
          const _ = a.ports[0];
          Jr.log("Angie is ready", a), Ua(_, { message: "Angie is ready" });
          break;
        }
        case xe.SDK_REQUEST_CLIENT_CREATION: {
          const _ = a.data.payload;
          try {
            const m = a.ports[0], h = new MessageChannel();
            h.port1.onmessage = ($) => {
              m.postMessage({ success: !0, data: $.data });
            };
            const g = { type: xe.SDK_REQUEST_CLIENT_CREATION, payload: { success: !0, ..._, clientId: `dynamic-client-${_.serverName}-${_.serverVersion}-${Date.now()}`, requestId: a.data.payload.requestId }, timestamp: Date.now() };
            if (!i.iframe) throw new Error("Iframe not found");
            (v = i.iframe.contentWindow) == null || v.postMessage(g, ((p = i.iframeUrlObject) == null ? void 0 : p.origin) || "", [h.port2]);
          } catch (m) {
            Jr.error(`Failed to create client for SDK server "${_.serverName}":`, m);
          }
          break;
        }
        case xe.SDK_TRIGGER_ANGIE:
          Jr.log("SDK Trigger Angie received", a.data);
          try {
            const { requestId: _, prompt: m, context: h, options: g } = a.data.payload;
            if (!i.iframe) throw new Error("Iframe not found");
            (b = i.iframe.contentWindow) == null || b.postMessage({ type: xe.SDK_TRIGGER_ANGIE, payload: { requestId: _, prompt: m, context: h, options: g } }, ((w = i.iframeUrlObject) == null ? void 0 : w.origin) || ""), window.postMessage({ type: xe.SDK_TRIGGER_ANGIE_RESPONSE, payload: { success: !0, requestId: _, response: "Angie triggered successfully" } }, window.location.origin);
          } catch (_) {
            Jr.error("Failed to trigger Angie:", _), window.postMessage({ type: xe.SDK_TRIGGER_ANGIE_RESPONSE, payload: { success: !1, requestId: (E = a.data.payload) == null ? void 0 : E.requestId, error: _ instanceof Error ? _.message : "Unknown error" } }, window.location.origin);
          }
      }
    });
  })(Ne), (function({ trustedOrigin: i, onOAuthParamsCleared: a }) {
    window.addEventListener("message", (o) => {
      var u;
      if (o.origin !== i) return;
      const c = (u = o.ports) == null ? void 0 : u[0];
      switch (o.data.type) {
        case Vu:
          if (!c) return;
          Es(c, { topUrl: window.location.href });
          break;
        case li:
          window.location.href = o.data.payload.url;
          break;
        case Ku: {
          if (!c) return;
          const l = o.data.payload.url;
          if (!(history != null && history.replaceState)) return void Ma(c, { message: "URL update not supported in this browser" });
          try {
            const p = window.location.href;
            history.replaceState({}, "", l), (function(v, w) {
              const b = new URL(v).searchParams, E = new URL(w).searchParams, _ = [Cr, Bn, Jn];
              return _.some((m) => b.has(m)) && !_.some((m) => E.has(m));
            })(p, l) && (a == null || a()), Es(c, { message: "URL updated successfully" });
          } catch (p) {
            Ma(c, { message: "URL update failed: " + (p instanceof Error ? p.message : "Unknown error") });
          }
          break;
        }
        case Gu:
          if (!c) return;
          Es(c, { isPending: new URLSearchParams(window.location.search).get(Cr) === "true" });
      }
    });
  })({ trustedOrigin: ((s = Ne.iframeUrlObject) == null ? void 0 : s.origin) ?? "", onOAuthParamsCleared: Ts }), (() => {
    const i = { window: Ne.iframe, windowURL: Ne.iframeUrlObject };
    window.addEventListener("load", () => {
      mi.log("OIDC: Window load event fired, forwarding OIDC state if present"), pi({ targets: i, onSuccess: Ts });
    }), pi({ targets: i, onSuccess: Ts });
  })(), new URLSearchParams(window.location.search).has("start-oauth") && (mi.log("Post-consent flow detected, checking for referrer redirect"), Lg()), window.addEventListener("message", async (i) => {
    var a, o, c, u, l;
    if ([window.location.origin, t.origin || "https://angie.elementor.com"].includes(i.origin)) if (((a = i == null ? void 0 : i.data) == null ? void 0 : a.type) === xe.ANGIE_CHAT_TOGGLE) Ne.open = i.data.open, Ne.iframe && Da(Ne.iframe, Ne.open);
    else if (((o = i == null ? void 0 : i.data) == null ? void 0 : o.type) === xe.ANGIE_STUDIO_TOGGLE) {
      const p = i.data.isStudioOpen;
      if (!Ne.iframe) return;
      if (p) document.documentElement.classList.add("angie-studio-active");
      else {
        const v = Hg();
        document.documentElement.style.setProperty("--angie-sidebar-width", `${v}px`), document.documentElement.classList.remove("angie-studio-active");
      }
    } else if (((c = i == null ? void 0 : i.data) == null ? void 0 : c.type) === xe.ANGIE_NAVIGATE_TO_URL) {
      const { url: p = "", confirmed: v = !1 } = i.data.payload || {};
      if (!v) return void it.log("Navigation requires user confirmation");
      if (!((w, b = []) => {
        const E = b.length === 0 && typeof window < "u" ? [window.location.origin] : b;
        if (!w.startsWith("http")) return !1;
        try {
          const _ = new URL(w);
          return E.includes(_.origin);
        } catch {
          return !1;
        }
      })(p)) return void it.error("Navigation blocked: Invalid or unsafe URL", { url: p });
      await Za(), window.location.assign(p);
    } else if (((u = i == null ? void 0 : i.data) == null ? void 0 : u.type) === xe.ANGIE_PAGE_RELOAD) {
      const { confirmed: p = !1 } = i.data.payload || {};
      if (!p) return void it.log("Page reload requires user confirmation");
      it.log("Page reload confirmed - disabling navigation prevention and reloading"), await Za(), setTimeout(() => {
        window.location.reload();
      }, 50);
    } else ((l = i == null ? void 0 : i.data) == null ? void 0 : l.type) === nr.RESET_HASH && (window.location.hash = "", Ua(i.ports[0], { message: "Hash reset successfully" }));
  });
}, kt = St("registration-queue");
class Jg {
  constructor() {
    ve(this, "queue", []);
    ve(this, "isProcessing", !1);
  }
  add(e) {
    const r = { id: this.generateId(e), config: e, timestamp: Date.now(), status: "pending" };
    return this.queue.push(r), kt.log(`Added server "${e.name}" to queue`), r;
  }
  getAll() {
    return [...this.queue];
  }
  getPending() {
    return this.queue.filter((e) => e.status === "pending");
  }
  updateStatus(e, r, n) {
    const s = this.queue.find((i) => i.id === e);
    s && (s.status = r, n ? s.error = n : r !== "pending" && r !== "registered" || delete s.error, kt.log(`Updated server ${e} status to ${r}`));
  }
  async processQueue(e) {
    if (this.isProcessing) return void kt.log("Already processing queue");
    this.isProcessing = !0;
    const r = this.getPending();
    kt.log(`Processing ${r.length} pending registrations`);
    try {
      for (const n of r) try {
        await e(n), this.updateStatus(n.id, "registered");
      } catch (s) {
        const i = s instanceof Error ? s.message : String(s);
        this.updateStatus(n.id, "failed", i), kt.error(`Failed to process registration ${n.id}:`, i);
      }
    } finally {
      this.isProcessing = !1;
    }
  }
  clear() {
    this.queue = [], kt.log("Cleared all registrations");
  }
  resetAllToPending() {
    if (this.isProcessing) return kt.log("Cannot reset to pending - processing in progress"), !1;
    const e = this.queue.filter((n) => n.status === "registered").length, r = this.queue.filter((n) => n.status === "failed").length;
    return this.queue.forEach((n) => {
      n.status !== "pending" && (n.status = "pending", delete n.error);
    }), kt.log(`Reset ${e + r} registrations to pending`), !0;
  }
  remove(e) {
    const r = this.queue.findIndex((n) => n.id === e);
    return r !== -1 && (this.queue.splice(r, 1), kt.log(`Removed registration ${e}`), !0);
  }
  generateId(e) {
    return `reg_${e.name}_${e.version}_${Date.now()}`;
  }
}
const Fa = "angie-prompt", Bg = { origin: "https://angie.elementor.com", uiTheme: "light", isRTL: !1, containerId: Cu, skipDefaultCss: !1, path: "angie/wp-admin" };
class Qg {
  constructor() {
    ve(this, "angieDetector");
    ve(this, "clientManager");
    ve(this, "logger");
    ve(this, "registrationQueue");
    ve(this, "isInitialized", !1);
    ve(this, "instanceId");
    this.instanceId = Math.random().toString(36).substring(2, 8), this.logger = St({ instanceId: this.instanceId }), this.logger.log("Constructor called - initializing SDK"), this.angieDetector = new og(), this.registrationQueue = new Jg(), this.clientManager = new ug(), this.logger.log("Setting up event handlers"), this.setupAngieReadyHandler(), this.setupServerInitHandler(), this.setupReRegistrationHandler(), this.logger.log("SDK initialization complete");
  }
  async loadSidebar(e) {
    const { widgetConfig: r, ...n } = e || {}, s = { ...Bg, ...n };
    Ne.containerId = s.containerId, Kg({ skipDefaultCss: s.skipDefaultCss }), await Wg(s), r && ni({ type: "sdk-widget-config", payload: r }), this.setupPromptHashDetection();
  }
  setupReRegistrationHandler() {
    window.addEventListener("message", (e) => {
      var r;
      if (((r = e.data) == null ? void 0 : r.type) === xe.SDK_ANGIE_REFRESH_PING) if (this.logger.log("Angie refresh ping received"), this.registrationQueue.resetAllToPending()) {
        const n = this.registrationQueue.getPending().length;
        this.logger.log(`Successfully reset ${n} registrations, processing queue`), this.handleAngieReady();
      } else this.logger.log("Skipping queue reset - processing already in progress");
    });
  }
  setupAngieReadyHandler() {
    this.angieDetector.waitForReady().then((e) => {
      e.isReady ? this.handleAngieReady() : this.logger.warn("Angie not detected - servers will remain queued");
    }).catch((e) => {
      this.logger.error("Error waiting for Angie:", e);
    });
  }
  async handleAngieReady() {
    this.logger.log("Angie is ready, processing queued registrations");
    try {
      await this.registrationQueue.processQueue(async (e) => {
        this.logger.log(`processQueue callback called for "${e.config.name}"`), await this.processRegistration(e);
      }), this.isInitialized = !0, this.logger.log("Initialization complete");
    } catch (e) {
      this.logger.error("Error processing registration queue:", e);
    }
  }
  async processRegistration(e) {
    this.logger.log(`Processing registration for server "${e.config.name}" (ID: ${e.id})`);
    try {
      this.logger.log(`Calling clientManager.requestClientCreation for "${e.config.name}"`);
      const r = { ...e, instanceId: this.instanceId };
      await this.clientManager.requestClientCreation(r), this.logger.log(`Successfully registered server "${e.config.name}"`);
    } catch (r) {
      throw this.logger.error(`Failed to register server "${e.config.name}":`, r), r;
    }
  }
  registerLocalServer(e) {
    return e.type = rr.LOCAL, e.transport = Kn.POST_MESSAGE, this.registerServer(e);
  }
  registerRemoteServer(e) {
    return e.type = rr.REMOTE, this.registerServer(e);
  }
  isLocalServerConfig(e) {
    return e.type === rr.LOCAL || !e.type && "server" in e;
  }
  isRemoteServerConfig(e) {
    return e.type === rr.REMOTE && "url" in e;
  }
  async registerServer(e) {
    if (!e.type) return this.logger.warn("For a local server, please use registerLocalServer instead of registerServer"), void this.registerLocalServer(e);
    if (this.logger.log(`registerServer called for "${e.name}"`), !e.name) throw new Error("Server name is required");
    if (!e.description) throw new Error("Server description is required");
    if (this.isLocalServerConfig(e) && !e.server) throw new Error("Server instance is required for local servers");
    this.logger.log(`Registering server "${e.name}"`);
    const r = this.registrationQueue.add(e);
    if (this.logger.log(`Added registration to queue: ${r.id}`), this.angieDetector.isReady()) try {
      await this.processRegistration(r), this.registrationQueue.updateStatus(r.id, "registered"), this.logger.log(`Server "${e.name}" registered successfully`);
    } catch (n) {
      const s = n instanceof Error ? n.message : String(n);
      throw this.registrationQueue.updateStatus(r.id, "failed", s), n;
    }
    else this.logger.log(`Server "${e.name}" queued until Angie is ready`);
  }
  getRegistrations() {
    return this.registrationQueue.getAll();
  }
  getPendingRegistrations() {
    return this.registrationQueue.getPending();
  }
  isAngieReady() {
    return this.angieDetector.isReady();
  }
  isReady() {
    return this.isInitialized;
  }
  async waitForReady() {
    if (!(await this.angieDetector.waitForReady()).isReady) throw new Error("Angie is not available");
    for (; !this.isInitialized; ) await new Promise((e) => setTimeout(e, 100));
  }
  async triggerAngie(e) {
    var s;
    if (!this.isAngieReady()) throw new Error("Angie is not ready. Please wait for Angie to be available before triggering.");
    const r = this.generateRequestId(), n = ((s = e.options) == null ? void 0 : s.timeout) || 3e4;
    return new Promise((i, a) => {
      const o = setTimeout(() => {
        a(new Error("Angie trigger request timed out"));
      }, n), c = (l) => {
        var p, v, w;
        ((p = l.data) == null ? void 0 : p.type) === xe.SDK_TRIGGER_ANGIE_RESPONSE && ((w = (v = l.data) == null ? void 0 : v.payload) == null ? void 0 : w.requestId) === r && (clearTimeout(o), window.removeEventListener("message", c), i(l.data.payload));
      };
      window.addEventListener("message", c);
      const u = { type: xe.SDK_TRIGGER_ANGIE, payload: { requestId: r, prompt: e.prompt, options: e.options, context: { pageUrl: window.location.href, pageTitle: document.title, ...e.context } }, timestamp: Date.now() };
      this.logger.log(`Triggering Angie with prompt (Request ID: ${r})`), window.postMessage(u, window.location.origin);
    });
  }
  destroy() {
    this.registrationQueue.clear(), this.logger.log("SDK destroyed");
  }
  setupServerInitHandler() {
    window.addEventListener("message", (e) => {
      var r;
      ((r = e.data) == null ? void 0 : r.type) === xe.SDK_REQUEST_INIT_SERVER && (this.logger.log("Server init request received"), this.handleServerInitRequest(e));
    });
  }
  handleServerInitRequest(e) {
    const { clientId: r, serverId: n, instanceId: s } = e.data.payload || {};
    if (r && n) if (this.logger.log(`Server init request received - Request instanceId: ${s}, This instanceId: ${this.instanceId}`), s && s !== this.instanceId) this.logger.log(`Ignoring server init request for different instance. Request instanceId: ${s}, this instanceId: ${this.instanceId}`);
    else {
      this.logger.log(`Handling server init request for clientId: ${r}, serverId: ${n}`);
      try {
        const i = this.registrationQueue.getAll().find((u) => u.id === n);
        if (!i) return void this.logger.error(`No registration found for serverId: ${n}`);
        if ("type" in i.config && i.config.type === "remote") return void this.logger.log("Remote server registration detected; skipping local connect");
        const a = e.ports[0];
        if (!a) return void this.logger.error("No port provided in server init request");
        const o = i.config.server;
        this.migrateInstructionsCompat(o);
        const c = new cg(a);
        o.connect(c), this.logger.log(`Server "${i.config.name}" initialized successfully`);
      } catch (i) {
        this.logger.error(`Error initializing server for clientId ${r}:`, i);
      }
    }
    else this.logger.error("Invalid server init request - missing clientId or serverId");
  }
  migrateInstructionsCompat(e) {
    try {
      const r = "server" in e && e.server ? e.server : e, n = r._serverInfo, s = r._instructions;
      n != null && n.instructions && !s && (r._instructions = n.instructions, this.logger.log("Migrated instructions from serverInfo to serverOptions (backward compat)"));
    } catch {
    }
  }
  generateRequestId() {
    return `${this.instanceId}-${Date.now()}-${Math.random().toString(36).substring(2, 8)}`;
  }
  parseHashParams(e) {
    const r = e.startsWith("#") ? e.substring(1) : e;
    return new URLSearchParams(r);
  }
  async handlePromptHash() {
    const e = window.location.hash;
    if (e.includes(`${Fa}=`)) try {
      const r = this.parseHashParams(e), n = r.get(Fa) || "";
      if (!n) return void this.logger.warn("Empty prompt detected in hash");
      const s = r.get("angie-new-chat") === "true";
      this.logger.log("Detected prompt in hash:", { prompt: n, newChat: s }), await this.waitForReady();
      const i = await this.triggerAngie({ prompt: n, context: { source: "hash-parameter", pageUrl: window.location.href, timestamp: (/* @__PURE__ */ new Date()).toISOString() }, options: { newChat: s } });
      this.logger.log("Triggered successfully from hash:", i), window.location.hash = "";
    } catch (r) {
      this.logger.error("Failed to trigger from hash:", r);
    }
  }
  setupPromptHashDetection() {
    this.handlePromptHash(), window.addEventListener("hashchange", () => this.handlePromptHash());
  }
}
St("navigation");
var Ha;
(function(t) {
  t.Inline = "inline", t.EndOfTurn = "end-of-turn";
})(Ha || (Ha = {}));
var ge;
(function(t) {
  t.assertEqual = (s) => {
  };
  function e(s) {
  }
  t.assertIs = e;
  function r(s) {
    throw new Error();
  }
  t.assertNever = r, t.arrayToEnum = (s) => {
    const i = {};
    for (const a of s)
      i[a] = a;
    return i;
  }, t.getValidEnumValues = (s) => {
    const i = t.objectKeys(s).filter((o) => typeof s[s[o]] != "number"), a = {};
    for (const o of i)
      a[o] = s[o];
    return t.objectValues(a);
  }, t.objectValues = (s) => t.objectKeys(s).map(function(i) {
    return s[i];
  }), t.objectKeys = typeof Object.keys == "function" ? (s) => Object.keys(s) : (s) => {
    const i = [];
    for (const a in s)
      Object.prototype.hasOwnProperty.call(s, a) && i.push(a);
    return i;
  }, t.find = (s, i) => {
    for (const a of s)
      if (i(a))
        return a;
  }, t.isInteger = typeof Number.isInteger == "function" ? (s) => Number.isInteger(s) : (s) => typeof s == "number" && Number.isFinite(s) && Math.floor(s) === s;
  function n(s, i = " | ") {
    return s.map((a) => typeof a == "string" ? `'${a}'` : a).join(i);
  }
  t.joinValues = n, t.jsonStringifyReplacer = (s, i) => typeof i == "bigint" ? i.toString() : i;
})(ge || (ge = {}));
var Va;
(function(t) {
  t.mergeShapes = (e, r) => ({
    ...e,
    ...r
    // second overwrites first
  });
})(Va || (Va = {}));
const W = ge.arrayToEnum([
  "string",
  "nan",
  "number",
  "integer",
  "float",
  "boolean",
  "date",
  "bigint",
  "symbol",
  "function",
  "undefined",
  "null",
  "array",
  "object",
  "unknown",
  "promise",
  "void",
  "never",
  "map",
  "set"
]), xt = (t) => {
  switch (typeof t) {
    case "undefined":
      return W.undefined;
    case "string":
      return W.string;
    case "number":
      return Number.isNaN(t) ? W.nan : W.number;
    case "boolean":
      return W.boolean;
    case "function":
      return W.function;
    case "bigint":
      return W.bigint;
    case "symbol":
      return W.symbol;
    case "object":
      return Array.isArray(t) ? W.array : t === null ? W.null : t.then && typeof t.then == "function" && t.catch && typeof t.catch == "function" ? W.promise : typeof Map < "u" && t instanceof Map ? W.map : typeof Set < "u" && t instanceof Set ? W.set : typeof Date < "u" && t instanceof Date ? W.date : W.object;
    default:
      return W.unknown;
  }
}, U = ge.arrayToEnum([
  "invalid_type",
  "invalid_literal",
  "custom",
  "invalid_union",
  "invalid_union_discriminator",
  "invalid_enum_value",
  "unrecognized_keys",
  "invalid_arguments",
  "invalid_return_type",
  "invalid_date",
  "invalid_string",
  "too_small",
  "too_big",
  "invalid_intersection_types",
  "not_multiple_of",
  "not_finite"
]);
class Ot extends Error {
  get errors() {
    return this.issues;
  }
  constructor(e) {
    super(), this.issues = [], this.addIssue = (n) => {
      this.issues = [...this.issues, n];
    }, this.addIssues = (n = []) => {
      this.issues = [...this.issues, ...n];
    };
    const r = new.target.prototype;
    Object.setPrototypeOf ? Object.setPrototypeOf(this, r) : this.__proto__ = r, this.name = "ZodError", this.issues = e;
  }
  format(e) {
    const r = e || function(i) {
      return i.message;
    }, n = { _errors: [] }, s = (i) => {
      for (const a of i.issues)
        if (a.code === "invalid_union")
          a.unionErrors.map(s);
        else if (a.code === "invalid_return_type")
          s(a.returnTypeError);
        else if (a.code === "invalid_arguments")
          s(a.argumentsError);
        else if (a.path.length === 0)
          n._errors.push(r(a));
        else {
          let o = n, c = 0;
          for (; c < a.path.length; ) {
            const u = a.path[c];
            c === a.path.length - 1 ? (o[u] = o[u] || { _errors: [] }, o[u]._errors.push(r(a))) : o[u] = o[u] || { _errors: [] }, o = o[u], c++;
          }
        }
    };
    return s(this), n;
  }
  static assert(e) {
    if (!(e instanceof Ot))
      throw new Error(`Not a ZodError: ${e}`);
  }
  toString() {
    return this.message;
  }
  get message() {
    return JSON.stringify(this.issues, ge.jsonStringifyReplacer, 2);
  }
  get isEmpty() {
    return this.issues.length === 0;
  }
  flatten(e = (r) => r.message) {
    const r = {}, n = [];
    for (const s of this.issues)
      if (s.path.length > 0) {
        const i = s.path[0];
        r[i] = r[i] || [], r[i].push(e(s));
      } else
        n.push(e(s));
    return { formErrors: n, fieldErrors: r };
  }
  get formErrors() {
    return this.flatten();
  }
}
Ot.create = (t) => new Ot(t);
const gi = (t, e) => {
  let r;
  switch (t.code) {
    case U.invalid_type:
      t.received === W.undefined ? r = "Required" : r = `Expected ${t.expected}, received ${t.received}`;
      break;
    case U.invalid_literal:
      r = `Invalid literal value, expected ${JSON.stringify(t.expected, ge.jsonStringifyReplacer)}`;
      break;
    case U.unrecognized_keys:
      r = `Unrecognized key(s) in object: ${ge.joinValues(t.keys, ", ")}`;
      break;
    case U.invalid_union:
      r = "Invalid input";
      break;
    case U.invalid_union_discriminator:
      r = `Invalid discriminator value. Expected ${ge.joinValues(t.options)}`;
      break;
    case U.invalid_enum_value:
      r = `Invalid enum value. Expected ${ge.joinValues(t.options)}, received '${t.received}'`;
      break;
    case U.invalid_arguments:
      r = "Invalid function arguments";
      break;
    case U.invalid_return_type:
      r = "Invalid function return type";
      break;
    case U.invalid_date:
      r = "Invalid date";
      break;
    case U.invalid_string:
      typeof t.validation == "object" ? "includes" in t.validation ? (r = `Invalid input: must include "${t.validation.includes}"`, typeof t.validation.position == "number" && (r = `${r} at one or more positions greater than or equal to ${t.validation.position}`)) : "startsWith" in t.validation ? r = `Invalid input: must start with "${t.validation.startsWith}"` : "endsWith" in t.validation ? r = `Invalid input: must end with "${t.validation.endsWith}"` : ge.assertNever(t.validation) : t.validation !== "regex" ? r = `Invalid ${t.validation}` : r = "Invalid";
      break;
    case U.too_small:
      t.type === "array" ? r = `Array must contain ${t.exact ? "exactly" : t.inclusive ? "at least" : "more than"} ${t.minimum} element(s)` : t.type === "string" ? r = `String must contain ${t.exact ? "exactly" : t.inclusive ? "at least" : "over"} ${t.minimum} character(s)` : t.type === "number" ? r = `Number must be ${t.exact ? "exactly equal to " : t.inclusive ? "greater than or equal to " : "greater than "}${t.minimum}` : t.type === "bigint" ? r = `Number must be ${t.exact ? "exactly equal to " : t.inclusive ? "greater than or equal to " : "greater than "}${t.minimum}` : t.type === "date" ? r = `Date must be ${t.exact ? "exactly equal to " : t.inclusive ? "greater than or equal to " : "greater than "}${new Date(Number(t.minimum))}` : r = "Invalid input";
      break;
    case U.too_big:
      t.type === "array" ? r = `Array must contain ${t.exact ? "exactly" : t.inclusive ? "at most" : "less than"} ${t.maximum} element(s)` : t.type === "string" ? r = `String must contain ${t.exact ? "exactly" : t.inclusive ? "at most" : "under"} ${t.maximum} character(s)` : t.type === "number" ? r = `Number must be ${t.exact ? "exactly" : t.inclusive ? "less than or equal to" : "less than"} ${t.maximum}` : t.type === "bigint" ? r = `BigInt must be ${t.exact ? "exactly" : t.inclusive ? "less than or equal to" : "less than"} ${t.maximum}` : t.type === "date" ? r = `Date must be ${t.exact ? "exactly" : t.inclusive ? "smaller than or equal to" : "smaller than"} ${new Date(Number(t.maximum))}` : r = "Invalid input";
      break;
    case U.custom:
      r = "Invalid input";
      break;
    case U.invalid_intersection_types:
      r = "Intersection results could not be merged";
      break;
    case U.not_multiple_of:
      r = `Number must be a multiple of ${t.multipleOf}`;
      break;
    case U.not_finite:
      r = "Number must be finite";
      break;
    default:
      r = e.defaultError, ge.assertNever(t);
  }
  return { message: r };
};
let Yg = gi;
function Xg() {
  return Yg;
}
const e_ = (t) => {
  const { data: e, path: r, errorMaps: n, issueData: s } = t, i = [...r, ...s.path || []], a = {
    ...s,
    path: i
  };
  if (s.message !== void 0)
    return {
      ...s,
      path: i,
      message: s.message
    };
  let o = "";
  const c = n.filter((u) => !!u).slice().reverse();
  for (const u of c)
    o = u(a, { data: e, defaultError: o }).message;
  return {
    ...s,
    path: i,
    message: o
  };
};
function K(t, e) {
  const r = Xg(), n = e_({
    issueData: e,
    data: t.data,
    path: t.path,
    errorMaps: [
      t.common.contextualErrorMap,
      // contextual error map is first priority
      t.schemaErrorMap,
      // then schema-bound map if available
      r,
      // then global override map
      r === gi ? void 0 : gi
      // then global default map
    ].filter((s) => !!s)
  });
  t.common.issues.push(n);
}
class We {
  constructor() {
    this.value = "valid";
  }
  dirty() {
    this.value === "valid" && (this.value = "dirty");
  }
  abort() {
    this.value !== "aborted" && (this.value = "aborted");
  }
  static mergeArray(e, r) {
    const n = [];
    for (const s of r) {
      if (s.status === "aborted")
        return ne;
      s.status === "dirty" && e.dirty(), n.push(s.value);
    }
    return { status: e.value, value: n };
  }
  static async mergeObjectAsync(e, r) {
    const n = [];
    for (const s of r) {
      const i = await s.key, a = await s.value;
      n.push({
        key: i,
        value: a
      });
    }
    return We.mergeObjectSync(e, n);
  }
  static mergeObjectSync(e, r) {
    const n = {};
    for (const s of r) {
      const { key: i, value: a } = s;
      if (i.status === "aborted" || a.status === "aborted")
        return ne;
      i.status === "dirty" && e.dirty(), a.status === "dirty" && e.dirty(), i.value !== "__proto__" && (typeof a.value < "u" || s.alwaysSet) && (n[i.value] = a.value);
    }
    return { status: e.value, value: n };
  }
}
const ne = Object.freeze({
  status: "aborted"
}), Er = (t) => ({ status: "dirty", value: t }), ct = (t) => ({ status: "valid", value: t }), Ka = (t) => t.status === "aborted", Ga = (t) => t.status === "dirty", or = (t) => t.status === "valid", Qn = (t) => typeof Promise < "u" && t instanceof Promise;
var B;
(function(t) {
  t.errToObj = (e) => typeof e == "string" ? { message: e } : e || {}, t.toString = (e) => typeof e == "string" ? e : e == null ? void 0 : e.message;
})(B || (B = {}));
class wt {
  constructor(e, r, n, s) {
    this._cachedPath = [], this.parent = e, this.data = r, this._path = n, this._key = s;
  }
  get path() {
    return this._cachedPath.length || (Array.isArray(this._key) ? this._cachedPath.push(...this._path, ...this._key) : this._cachedPath.push(...this._path, this._key)), this._cachedPath;
  }
}
const Wa = (t, e) => {
  if (or(e))
    return { success: !0, data: e.value };
  if (!t.common.issues.length)
    throw new Error("Validation failed but no issues detected.");
  return {
    success: !1,
    get error() {
      if (this._error)
        return this._error;
      const r = new Ot(t.common.issues);
      return this._error = r, this._error;
    }
  };
};
function ie(t) {
  if (!t)
    return {};
  const { errorMap: e, invalid_type_error: r, required_error: n, description: s } = t;
  if (e && (r || n))
    throw new Error(`Can't use "invalid_type_error" or "required_error" in conjunction with custom error map.`);
  return e ? { errorMap: e, description: s } : { errorMap: (a, o) => {
    const { message: c } = t;
    return a.code === "invalid_enum_value" ? { message: c ?? o.defaultError } : typeof o.data > "u" ? { message: c ?? n ?? o.defaultError } : a.code !== "invalid_type" ? { message: o.defaultError } : { message: c ?? r ?? o.defaultError };
  }, description: s };
}
class le {
  get description() {
    return this._def.description;
  }
  _getType(e) {
    return xt(e.data);
  }
  _getOrReturnCtx(e, r) {
    return r || {
      common: e.parent.common,
      data: e.data,
      parsedType: xt(e.data),
      schemaErrorMap: this._def.errorMap,
      path: e.path,
      parent: e.parent
    };
  }
  _processInputParams(e) {
    return {
      status: new We(),
      ctx: {
        common: e.parent.common,
        data: e.data,
        parsedType: xt(e.data),
        schemaErrorMap: this._def.errorMap,
        path: e.path,
        parent: e.parent
      }
    };
  }
  _parseSync(e) {
    const r = this._parse(e);
    if (Qn(r))
      throw new Error("Synchronous parse encountered promise.");
    return r;
  }
  _parseAsync(e) {
    const r = this._parse(e);
    return Promise.resolve(r);
  }
  parse(e, r) {
    const n = this.safeParse(e, r);
    if (n.success)
      return n.data;
    throw n.error;
  }
  safeParse(e, r) {
    const n = {
      common: {
        issues: [],
        async: (r == null ? void 0 : r.async) ?? !1,
        contextualErrorMap: r == null ? void 0 : r.errorMap
      },
      path: (r == null ? void 0 : r.path) || [],
      schemaErrorMap: this._def.errorMap,
      parent: null,
      data: e,
      parsedType: xt(e)
    }, s = this._parseSync({ data: e, path: n.path, parent: n });
    return Wa(n, s);
  }
  "~validate"(e) {
    var n, s;
    const r = {
      common: {
        issues: [],
        async: !!this["~standard"].async
      },
      path: [],
      schemaErrorMap: this._def.errorMap,
      parent: null,
      data: e,
      parsedType: xt(e)
    };
    if (!this["~standard"].async)
      try {
        const i = this._parseSync({ data: e, path: [], parent: r });
        return or(i) ? {
          value: i.value
        } : {
          issues: r.common.issues
        };
      } catch (i) {
        (s = (n = i == null ? void 0 : i.message) == null ? void 0 : n.toLowerCase()) != null && s.includes("encountered") && (this["~standard"].async = !0), r.common = {
          issues: [],
          async: !0
        };
      }
    return this._parseAsync({ data: e, path: [], parent: r }).then((i) => or(i) ? {
      value: i.value
    } : {
      issues: r.common.issues
    });
  }
  async parseAsync(e, r) {
    const n = await this.safeParseAsync(e, r);
    if (n.success)
      return n.data;
    throw n.error;
  }
  async safeParseAsync(e, r) {
    const n = {
      common: {
        issues: [],
        contextualErrorMap: r == null ? void 0 : r.errorMap,
        async: !0
      },
      path: (r == null ? void 0 : r.path) || [],
      schemaErrorMap: this._def.errorMap,
      parent: null,
      data: e,
      parsedType: xt(e)
    }, s = this._parse({ data: e, path: n.path, parent: n }), i = await (Qn(s) ? s : Promise.resolve(s));
    return Wa(n, i);
  }
  refine(e, r) {
    const n = (s) => typeof r == "string" || typeof r > "u" ? { message: r } : typeof r == "function" ? r(s) : r;
    return this._refinement((s, i) => {
      const a = e(s), o = () => i.addIssue({
        code: U.custom,
        ...n(s)
      });
      return typeof Promise < "u" && a instanceof Promise ? a.then((c) => c ? !0 : (o(), !1)) : a ? !0 : (o(), !1);
    });
  }
  refinement(e, r) {
    return this._refinement((n, s) => e(n) ? !0 : (s.addIssue(typeof r == "function" ? r(n, s) : r), !1));
  }
  _refinement(e) {
    return new lr({
      schema: this,
      typeName: Z.ZodEffects,
      effect: { type: "refinement", refinement: e }
    });
  }
  superRefine(e) {
    return this._refinement(e);
  }
  constructor(e) {
    this.spa = this.safeParseAsync, this._def = e, this.parse = this.parse.bind(this), this.safeParse = this.safeParse.bind(this), this.parseAsync = this.parseAsync.bind(this), this.safeParseAsync = this.safeParseAsync.bind(this), this.spa = this.spa.bind(this), this.refine = this.refine.bind(this), this.refinement = this.refinement.bind(this), this.superRefine = this.superRefine.bind(this), this.optional = this.optional.bind(this), this.nullable = this.nullable.bind(this), this.nullish = this.nullish.bind(this), this.array = this.array.bind(this), this.promise = this.promise.bind(this), this.or = this.or.bind(this), this.and = this.and.bind(this), this.transform = this.transform.bind(this), this.brand = this.brand.bind(this), this.default = this.default.bind(this), this.catch = this.catch.bind(this), this.describe = this.describe.bind(this), this.pipe = this.pipe.bind(this), this.readonly = this.readonly.bind(this), this.isNullable = this.isNullable.bind(this), this.isOptional = this.isOptional.bind(this), this["~standard"] = {
      version: 1,
      vendor: "zod",
      validate: (r) => this["~validate"](r)
    };
  }
  optional() {
    return It.create(this, this._def);
  }
  nullable() {
    return dr.create(this, this._def);
  }
  nullish() {
    return this.nullable().optional();
  }
  array() {
    return vt.create(this);
  }
  promise() {
    return rs.create(this, this._def);
  }
  or(e) {
    return Xn.create([this, e], this._def);
  }
  and(e) {
    return es.create(this, e, this._def);
  }
  transform(e) {
    return new lr({
      ...ie(this._def),
      schema: this,
      typeName: Z.ZodEffects,
      effect: { type: "transform", transform: e }
    });
  }
  default(e) {
    const r = typeof e == "function" ? e : () => e;
    return new wi({
      ...ie(this._def),
      innerType: this,
      defaultValue: r,
      typeName: Z.ZodDefault
    });
  }
  brand() {
    return new k_({
      typeName: Z.ZodBranded,
      type: this,
      ...ie(this._def)
    });
  }
  catch(e) {
    const r = typeof e == "function" ? e : () => e;
    return new bi({
      ...ie(this._def),
      innerType: this,
      catchValue: r,
      typeName: Z.ZodCatch
    });
  }
  describe(e) {
    const r = this.constructor;
    return new r({
      ...this._def,
      description: e
    });
  }
  pipe(e) {
    return sa.create(this, e);
  }
  readonly() {
    return Si.create(this);
  }
  isOptional() {
    return this.safeParse(void 0).success;
  }
  isNullable() {
    return this.safeParse(null).success;
  }
}
const t_ = /^c[^\s-]{8,}$/i, r_ = /^[0-9a-z]+$/, n_ = /^[0-9A-HJKMNP-TV-Z]{26}$/i, s_ = /^[0-9a-fA-F]{8}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{12}$/i, i_ = /^[a-z0-9_-]{21}$/i, a_ = /^[A-Za-z0-9-_]+\.[A-Za-z0-9-_]+\.[A-Za-z0-9-_]*$/, o_ = /^[-+]?P(?!$)(?:(?:[-+]?\d+Y)|(?:[-+]?\d+[.,]\d+Y$))?(?:(?:[-+]?\d+M)|(?:[-+]?\d+[.,]\d+M$))?(?:(?:[-+]?\d+W)|(?:[-+]?\d+[.,]\d+W$))?(?:(?:[-+]?\d+D)|(?:[-+]?\d+[.,]\d+D$))?(?:T(?=[\d+-])(?:(?:[-+]?\d+H)|(?:[-+]?\d+[.,]\d+H$))?(?:(?:[-+]?\d+M)|(?:[-+]?\d+[.,]\d+M$))?(?:[-+]?\d+(?:[.,]\d+)?S)?)??$/, c_ = /^(?!\.)(?!.*\.\.)([A-Z0-9_'+\-\.]*)[A-Z0-9_+-]@([A-Z0-9][A-Z0-9\-]*\.)+[A-Z]{2,}$/i, u_ = "^(\\p{Extended_Pictographic}|\\p{Emoji_Component})+$";
let Is;
const l_ = /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])$/, d_ = /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\/(3[0-2]|[12]?[0-9])$/, h_ = /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/, f_ = /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))\/(12[0-8]|1[01][0-9]|[1-9]?[0-9])$/, p_ = /^([0-9a-zA-Z+/]{4})*(([0-9a-zA-Z+/]{2}==)|([0-9a-zA-Z+/]{3}=))?$/, m_ = /^([0-9a-zA-Z-_]{4})*(([0-9a-zA-Z-_]{2}(==)?)|([0-9a-zA-Z-_]{3}(=)?))?$/, Yu = "((\\d\\d[2468][048]|\\d\\d[13579][26]|\\d\\d0[48]|[02468][048]00|[13579][26]00)-02-29|\\d{4}-((0[13578]|1[02])-(0[1-9]|[12]\\d|3[01])|(0[469]|11)-(0[1-9]|[12]\\d|30)|(02)-(0[1-9]|1\\d|2[0-8])))", g_ = new RegExp(`^${Yu}$`);
function Xu(t) {
  let e = "[0-5]\\d";
  t.precision ? e = `${e}\\.\\d{${t.precision}}` : t.precision == null && (e = `${e}(\\.\\d+)?`);
  const r = t.precision ? "+" : "?";
  return `([01]\\d|2[0-3]):[0-5]\\d(:${e})${r}`;
}
function __(t) {
  return new RegExp(`^${Xu(t)}$`);
}
function y_(t) {
  let e = `${Yu}T${Xu(t)}`;
  const r = [];
  return r.push(t.local ? "Z?" : "Z"), t.offset && r.push("([+-]\\d{2}:?\\d{2})"), e = `${e}(${r.join("|")})`, new RegExp(`^${e}$`);
}
function v_(t, e) {
  return !!((e === "v4" || !e) && l_.test(t) || (e === "v6" || !e) && h_.test(t));
}
function w_(t, e) {
  if (!a_.test(t))
    return !1;
  try {
    const [r] = t.split(".");
    if (!r)
      return !1;
    const n = r.replace(/-/g, "+").replace(/_/g, "/").padEnd(r.length + (4 - r.length % 4) % 4, "="), s = JSON.parse(atob(n));
    return !(typeof s != "object" || s === null || "typ" in s && (s == null ? void 0 : s.typ) !== "JWT" || !s.alg || e && s.alg !== e);
  } catch {
    return !1;
  }
}
function b_(t, e) {
  return !!((e === "v4" || !e) && d_.test(t) || (e === "v6" || !e) && f_.test(t));
}
class Rt extends le {
  _parse(e) {
    if (this._def.coerce && (e.data = String(e.data)), this._getType(e) !== W.string) {
      const i = this._getOrReturnCtx(e);
      return K(i, {
        code: U.invalid_type,
        expected: W.string,
        received: i.parsedType
      }), ne;
    }
    const n = new We();
    let s;
    for (const i of this._def.checks)
      if (i.kind === "min")
        e.data.length < i.value && (s = this._getOrReturnCtx(e, s), K(s, {
          code: U.too_small,
          minimum: i.value,
          type: "string",
          inclusive: !0,
          exact: !1,
          message: i.message
        }), n.dirty());
      else if (i.kind === "max")
        e.data.length > i.value && (s = this._getOrReturnCtx(e, s), K(s, {
          code: U.too_big,
          maximum: i.value,
          type: "string",
          inclusive: !0,
          exact: !1,
          message: i.message
        }), n.dirty());
      else if (i.kind === "length") {
        const a = e.data.length > i.value, o = e.data.length < i.value;
        (a || o) && (s = this._getOrReturnCtx(e, s), a ? K(s, {
          code: U.too_big,
          maximum: i.value,
          type: "string",
          inclusive: !0,
          exact: !0,
          message: i.message
        }) : o && K(s, {
          code: U.too_small,
          minimum: i.value,
          type: "string",
          inclusive: !0,
          exact: !0,
          message: i.message
        }), n.dirty());
      } else if (i.kind === "email")
        c_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "email",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "emoji")
        Is || (Is = new RegExp(u_, "u")), Is.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "emoji",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "uuid")
        s_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "uuid",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "nanoid")
        i_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "nanoid",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "cuid")
        t_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "cuid",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "cuid2")
        r_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "cuid2",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "ulid")
        n_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
          validation: "ulid",
          code: U.invalid_string,
          message: i.message
        }), n.dirty());
      else if (i.kind === "url")
        try {
          new URL(e.data);
        } catch {
          s = this._getOrReturnCtx(e, s), K(s, {
            validation: "url",
            code: U.invalid_string,
            message: i.message
          }), n.dirty();
        }
      else i.kind === "regex" ? (i.regex.lastIndex = 0, i.regex.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "regex",
        code: U.invalid_string,
        message: i.message
      }), n.dirty())) : i.kind === "trim" ? e.data = e.data.trim() : i.kind === "includes" ? e.data.includes(i.value, i.position) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: { includes: i.value, position: i.position },
        message: i.message
      }), n.dirty()) : i.kind === "toLowerCase" ? e.data = e.data.toLowerCase() : i.kind === "toUpperCase" ? e.data = e.data.toUpperCase() : i.kind === "startsWith" ? e.data.startsWith(i.value) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: { startsWith: i.value },
        message: i.message
      }), n.dirty()) : i.kind === "endsWith" ? e.data.endsWith(i.value) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: { endsWith: i.value },
        message: i.message
      }), n.dirty()) : i.kind === "datetime" ? y_(i).test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: "datetime",
        message: i.message
      }), n.dirty()) : i.kind === "date" ? g_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: "date",
        message: i.message
      }), n.dirty()) : i.kind === "time" ? __(i).test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.invalid_string,
        validation: "time",
        message: i.message
      }), n.dirty()) : i.kind === "duration" ? o_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "duration",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : i.kind === "ip" ? v_(e.data, i.version) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "ip",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : i.kind === "jwt" ? w_(e.data, i.alg) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "jwt",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : i.kind === "cidr" ? b_(e.data, i.version) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "cidr",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : i.kind === "base64" ? p_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "base64",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : i.kind === "base64url" ? m_.test(e.data) || (s = this._getOrReturnCtx(e, s), K(s, {
        validation: "base64url",
        code: U.invalid_string,
        message: i.message
      }), n.dirty()) : ge.assertNever(i);
    return { status: n.value, value: e.data };
  }
  _regex(e, r, n) {
    return this.refinement((s) => e.test(s), {
      validation: r,
      code: U.invalid_string,
      ...B.errToObj(n)
    });
  }
  _addCheck(e) {
    return new Rt({
      ...this._def,
      checks: [...this._def.checks, e]
    });
  }
  email(e) {
    return this._addCheck({ kind: "email", ...B.errToObj(e) });
  }
  url(e) {
    return this._addCheck({ kind: "url", ...B.errToObj(e) });
  }
  emoji(e) {
    return this._addCheck({ kind: "emoji", ...B.errToObj(e) });
  }
  uuid(e) {
    return this._addCheck({ kind: "uuid", ...B.errToObj(e) });
  }
  nanoid(e) {
    return this._addCheck({ kind: "nanoid", ...B.errToObj(e) });
  }
  cuid(e) {
    return this._addCheck({ kind: "cuid", ...B.errToObj(e) });
  }
  cuid2(e) {
    return this._addCheck({ kind: "cuid2", ...B.errToObj(e) });
  }
  ulid(e) {
    return this._addCheck({ kind: "ulid", ...B.errToObj(e) });
  }
  base64(e) {
    return this._addCheck({ kind: "base64", ...B.errToObj(e) });
  }
  base64url(e) {
    return this._addCheck({
      kind: "base64url",
      ...B.errToObj(e)
    });
  }
  jwt(e) {
    return this._addCheck({ kind: "jwt", ...B.errToObj(e) });
  }
  ip(e) {
    return this._addCheck({ kind: "ip", ...B.errToObj(e) });
  }
  cidr(e) {
    return this._addCheck({ kind: "cidr", ...B.errToObj(e) });
  }
  datetime(e) {
    return typeof e == "string" ? this._addCheck({
      kind: "datetime",
      precision: null,
      offset: !1,
      local: !1,
      message: e
    }) : this._addCheck({
      kind: "datetime",
      precision: typeof (e == null ? void 0 : e.precision) > "u" ? null : e == null ? void 0 : e.precision,
      offset: (e == null ? void 0 : e.offset) ?? !1,
      local: (e == null ? void 0 : e.local) ?? !1,
      ...B.errToObj(e == null ? void 0 : e.message)
    });
  }
  date(e) {
    return this._addCheck({ kind: "date", message: e });
  }
  time(e) {
    return typeof e == "string" ? this._addCheck({
      kind: "time",
      precision: null,
      message: e
    }) : this._addCheck({
      kind: "time",
      precision: typeof (e == null ? void 0 : e.precision) > "u" ? null : e == null ? void 0 : e.precision,
      ...B.errToObj(e == null ? void 0 : e.message)
    });
  }
  duration(e) {
    return this._addCheck({ kind: "duration", ...B.errToObj(e) });
  }
  regex(e, r) {
    return this._addCheck({
      kind: "regex",
      regex: e,
      ...B.errToObj(r)
    });
  }
  includes(e, r) {
    return this._addCheck({
      kind: "includes",
      value: e,
      position: r == null ? void 0 : r.position,
      ...B.errToObj(r == null ? void 0 : r.message)
    });
  }
  startsWith(e, r) {
    return this._addCheck({
      kind: "startsWith",
      value: e,
      ...B.errToObj(r)
    });
  }
  endsWith(e, r) {
    return this._addCheck({
      kind: "endsWith",
      value: e,
      ...B.errToObj(r)
    });
  }
  min(e, r) {
    return this._addCheck({
      kind: "min",
      value: e,
      ...B.errToObj(r)
    });
  }
  max(e, r) {
    return this._addCheck({
      kind: "max",
      value: e,
      ...B.errToObj(r)
    });
  }
  length(e, r) {
    return this._addCheck({
      kind: "length",
      value: e,
      ...B.errToObj(r)
    });
  }
  /**
   * Equivalent to `.min(1)`
   */
  nonempty(e) {
    return this.min(1, B.errToObj(e));
  }
  trim() {
    return new Rt({
      ...this._def,
      checks: [...this._def.checks, { kind: "trim" }]
    });
  }
  toLowerCase() {
    return new Rt({
      ...this._def,
      checks: [...this._def.checks, { kind: "toLowerCase" }]
    });
  }
  toUpperCase() {
    return new Rt({
      ...this._def,
      checks: [...this._def.checks, { kind: "toUpperCase" }]
    });
  }
  get isDatetime() {
    return !!this._def.checks.find((e) => e.kind === "datetime");
  }
  get isDate() {
    return !!this._def.checks.find((e) => e.kind === "date");
  }
  get isTime() {
    return !!this._def.checks.find((e) => e.kind === "time");
  }
  get isDuration() {
    return !!this._def.checks.find((e) => e.kind === "duration");
  }
  get isEmail() {
    return !!this._def.checks.find((e) => e.kind === "email");
  }
  get isURL() {
    return !!this._def.checks.find((e) => e.kind === "url");
  }
  get isEmoji() {
    return !!this._def.checks.find((e) => e.kind === "emoji");
  }
  get isUUID() {
    return !!this._def.checks.find((e) => e.kind === "uuid");
  }
  get isNANOID() {
    return !!this._def.checks.find((e) => e.kind === "nanoid");
  }
  get isCUID() {
    return !!this._def.checks.find((e) => e.kind === "cuid");
  }
  get isCUID2() {
    return !!this._def.checks.find((e) => e.kind === "cuid2");
  }
  get isULID() {
    return !!this._def.checks.find((e) => e.kind === "ulid");
  }
  get isIP() {
    return !!this._def.checks.find((e) => e.kind === "ip");
  }
  get isCIDR() {
    return !!this._def.checks.find((e) => e.kind === "cidr");
  }
  get isBase64() {
    return !!this._def.checks.find((e) => e.kind === "base64");
  }
  get isBase64url() {
    return !!this._def.checks.find((e) => e.kind === "base64url");
  }
  get minLength() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "min" && (e === null || r.value > e) && (e = r.value);
    return e;
  }
  get maxLength() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "max" && (e === null || r.value < e) && (e = r.value);
    return e;
  }
}
Rt.create = (t) => new Rt({
  checks: [],
  typeName: Z.ZodString,
  coerce: (t == null ? void 0 : t.coerce) ?? !1,
  ...ie(t)
});
function S_(t, e) {
  const r = (t.toString().split(".")[1] || "").length, n = (e.toString().split(".")[1] || "").length, s = r > n ? r : n, i = Number.parseInt(t.toFixed(s).replace(".", "")), a = Number.parseInt(e.toFixed(s).replace(".", ""));
  return i % a / 10 ** s;
}
class cr extends le {
  constructor() {
    super(...arguments), this.min = this.gte, this.max = this.lte, this.step = this.multipleOf;
  }
  _parse(e) {
    if (this._def.coerce && (e.data = Number(e.data)), this._getType(e) !== W.number) {
      const i = this._getOrReturnCtx(e);
      return K(i, {
        code: U.invalid_type,
        expected: W.number,
        received: i.parsedType
      }), ne;
    }
    let n;
    const s = new We();
    for (const i of this._def.checks)
      i.kind === "int" ? ge.isInteger(e.data) || (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.invalid_type,
        expected: "integer",
        received: "float",
        message: i.message
      }), s.dirty()) : i.kind === "min" ? (i.inclusive ? e.data < i.value : e.data <= i.value) && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.too_small,
        minimum: i.value,
        type: "number",
        inclusive: i.inclusive,
        exact: !1,
        message: i.message
      }), s.dirty()) : i.kind === "max" ? (i.inclusive ? e.data > i.value : e.data >= i.value) && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.too_big,
        maximum: i.value,
        type: "number",
        inclusive: i.inclusive,
        exact: !1,
        message: i.message
      }), s.dirty()) : i.kind === "multipleOf" ? S_(e.data, i.value) !== 0 && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.not_multiple_of,
        multipleOf: i.value,
        message: i.message
      }), s.dirty()) : i.kind === "finite" ? Number.isFinite(e.data) || (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.not_finite,
        message: i.message
      }), s.dirty()) : ge.assertNever(i);
    return { status: s.value, value: e.data };
  }
  gte(e, r) {
    return this.setLimit("min", e, !0, B.toString(r));
  }
  gt(e, r) {
    return this.setLimit("min", e, !1, B.toString(r));
  }
  lte(e, r) {
    return this.setLimit("max", e, !0, B.toString(r));
  }
  lt(e, r) {
    return this.setLimit("max", e, !1, B.toString(r));
  }
  setLimit(e, r, n, s) {
    return new cr({
      ...this._def,
      checks: [
        ...this._def.checks,
        {
          kind: e,
          value: r,
          inclusive: n,
          message: B.toString(s)
        }
      ]
    });
  }
  _addCheck(e) {
    return new cr({
      ...this._def,
      checks: [...this._def.checks, e]
    });
  }
  int(e) {
    return this._addCheck({
      kind: "int",
      message: B.toString(e)
    });
  }
  positive(e) {
    return this._addCheck({
      kind: "min",
      value: 0,
      inclusive: !1,
      message: B.toString(e)
    });
  }
  negative(e) {
    return this._addCheck({
      kind: "max",
      value: 0,
      inclusive: !1,
      message: B.toString(e)
    });
  }
  nonpositive(e) {
    return this._addCheck({
      kind: "max",
      value: 0,
      inclusive: !0,
      message: B.toString(e)
    });
  }
  nonnegative(e) {
    return this._addCheck({
      kind: "min",
      value: 0,
      inclusive: !0,
      message: B.toString(e)
    });
  }
  multipleOf(e, r) {
    return this._addCheck({
      kind: "multipleOf",
      value: e,
      message: B.toString(r)
    });
  }
  finite(e) {
    return this._addCheck({
      kind: "finite",
      message: B.toString(e)
    });
  }
  safe(e) {
    return this._addCheck({
      kind: "min",
      inclusive: !0,
      value: Number.MIN_SAFE_INTEGER,
      message: B.toString(e)
    })._addCheck({
      kind: "max",
      inclusive: !0,
      value: Number.MAX_SAFE_INTEGER,
      message: B.toString(e)
    });
  }
  get minValue() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "min" && (e === null || r.value > e) && (e = r.value);
    return e;
  }
  get maxValue() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "max" && (e === null || r.value < e) && (e = r.value);
    return e;
  }
  get isInt() {
    return !!this._def.checks.find((e) => e.kind === "int" || e.kind === "multipleOf" && ge.isInteger(e.value));
  }
  get isFinite() {
    let e = null, r = null;
    for (const n of this._def.checks) {
      if (n.kind === "finite" || n.kind === "int" || n.kind === "multipleOf")
        return !0;
      n.kind === "min" ? (r === null || n.value > r) && (r = n.value) : n.kind === "max" && (e === null || n.value < e) && (e = n.value);
    }
    return Number.isFinite(r) && Number.isFinite(e);
  }
}
cr.create = (t) => new cr({
  checks: [],
  typeName: Z.ZodNumber,
  coerce: (t == null ? void 0 : t.coerce) || !1,
  ...ie(t)
});
class Ar extends le {
  constructor() {
    super(...arguments), this.min = this.gte, this.max = this.lte;
  }
  _parse(e) {
    if (this._def.coerce)
      try {
        e.data = BigInt(e.data);
      } catch {
        return this._getInvalidInput(e);
      }
    if (this._getType(e) !== W.bigint)
      return this._getInvalidInput(e);
    let n;
    const s = new We();
    for (const i of this._def.checks)
      i.kind === "min" ? (i.inclusive ? e.data < i.value : e.data <= i.value) && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.too_small,
        type: "bigint",
        minimum: i.value,
        inclusive: i.inclusive,
        message: i.message
      }), s.dirty()) : i.kind === "max" ? (i.inclusive ? e.data > i.value : e.data >= i.value) && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.too_big,
        type: "bigint",
        maximum: i.value,
        inclusive: i.inclusive,
        message: i.message
      }), s.dirty()) : i.kind === "multipleOf" ? e.data % i.value !== BigInt(0) && (n = this._getOrReturnCtx(e, n), K(n, {
        code: U.not_multiple_of,
        multipleOf: i.value,
        message: i.message
      }), s.dirty()) : ge.assertNever(i);
    return { status: s.value, value: e.data };
  }
  _getInvalidInput(e) {
    const r = this._getOrReturnCtx(e);
    return K(r, {
      code: U.invalid_type,
      expected: W.bigint,
      received: r.parsedType
    }), ne;
  }
  gte(e, r) {
    return this.setLimit("min", e, !0, B.toString(r));
  }
  gt(e, r) {
    return this.setLimit("min", e, !1, B.toString(r));
  }
  lte(e, r) {
    return this.setLimit("max", e, !0, B.toString(r));
  }
  lt(e, r) {
    return this.setLimit("max", e, !1, B.toString(r));
  }
  setLimit(e, r, n, s) {
    return new Ar({
      ...this._def,
      checks: [
        ...this._def.checks,
        {
          kind: e,
          value: r,
          inclusive: n,
          message: B.toString(s)
        }
      ]
    });
  }
  _addCheck(e) {
    return new Ar({
      ...this._def,
      checks: [...this._def.checks, e]
    });
  }
  positive(e) {
    return this._addCheck({
      kind: "min",
      value: BigInt(0),
      inclusive: !1,
      message: B.toString(e)
    });
  }
  negative(e) {
    return this._addCheck({
      kind: "max",
      value: BigInt(0),
      inclusive: !1,
      message: B.toString(e)
    });
  }
  nonpositive(e) {
    return this._addCheck({
      kind: "max",
      value: BigInt(0),
      inclusive: !0,
      message: B.toString(e)
    });
  }
  nonnegative(e) {
    return this._addCheck({
      kind: "min",
      value: BigInt(0),
      inclusive: !0,
      message: B.toString(e)
    });
  }
  multipleOf(e, r) {
    return this._addCheck({
      kind: "multipleOf",
      value: e,
      message: B.toString(r)
    });
  }
  get minValue() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "min" && (e === null || r.value > e) && (e = r.value);
    return e;
  }
  get maxValue() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "max" && (e === null || r.value < e) && (e = r.value);
    return e;
  }
}
Ar.create = (t) => new Ar({
  checks: [],
  typeName: Z.ZodBigInt,
  coerce: (t == null ? void 0 : t.coerce) ?? !1,
  ...ie(t)
});
class _i extends le {
  _parse(e) {
    if (this._def.coerce && (e.data = !!e.data), this._getType(e) !== W.boolean) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.boolean,
        received: n.parsedType
      }), ne;
    }
    return ct(e.data);
  }
}
_i.create = (t) => new _i({
  typeName: Z.ZodBoolean,
  coerce: (t == null ? void 0 : t.coerce) || !1,
  ...ie(t)
});
class Yn extends le {
  _parse(e) {
    if (this._def.coerce && (e.data = new Date(e.data)), this._getType(e) !== W.date) {
      const i = this._getOrReturnCtx(e);
      return K(i, {
        code: U.invalid_type,
        expected: W.date,
        received: i.parsedType
      }), ne;
    }
    if (Number.isNaN(e.data.getTime())) {
      const i = this._getOrReturnCtx(e);
      return K(i, {
        code: U.invalid_date
      }), ne;
    }
    const n = new We();
    let s;
    for (const i of this._def.checks)
      i.kind === "min" ? e.data.getTime() < i.value && (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.too_small,
        message: i.message,
        inclusive: !0,
        exact: !1,
        minimum: i.value,
        type: "date"
      }), n.dirty()) : i.kind === "max" ? e.data.getTime() > i.value && (s = this._getOrReturnCtx(e, s), K(s, {
        code: U.too_big,
        message: i.message,
        inclusive: !0,
        exact: !1,
        maximum: i.value,
        type: "date"
      }), n.dirty()) : ge.assertNever(i);
    return {
      status: n.value,
      value: new Date(e.data.getTime())
    };
  }
  _addCheck(e) {
    return new Yn({
      ...this._def,
      checks: [...this._def.checks, e]
    });
  }
  min(e, r) {
    return this._addCheck({
      kind: "min",
      value: e.getTime(),
      message: B.toString(r)
    });
  }
  max(e, r) {
    return this._addCheck({
      kind: "max",
      value: e.getTime(),
      message: B.toString(r)
    });
  }
  get minDate() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "min" && (e === null || r.value > e) && (e = r.value);
    return e != null ? new Date(e) : null;
  }
  get maxDate() {
    let e = null;
    for (const r of this._def.checks)
      r.kind === "max" && (e === null || r.value < e) && (e = r.value);
    return e != null ? new Date(e) : null;
  }
}
Yn.create = (t) => new Yn({
  checks: [],
  coerce: (t == null ? void 0 : t.coerce) || !1,
  typeName: Z.ZodDate,
  ...ie(t)
});
class Ja extends le {
  _parse(e) {
    if (this._getType(e) !== W.symbol) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.symbol,
        received: n.parsedType
      }), ne;
    }
    return ct(e.data);
  }
}
Ja.create = (t) => new Ja({
  typeName: Z.ZodSymbol,
  ...ie(t)
});
class Ba extends le {
  _parse(e) {
    if (this._getType(e) !== W.undefined) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.undefined,
        received: n.parsedType
      }), ne;
    }
    return ct(e.data);
  }
}
Ba.create = (t) => new Ba({
  typeName: Z.ZodUndefined,
  ...ie(t)
});
class Qa extends le {
  _parse(e) {
    if (this._getType(e) !== W.null) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.null,
        received: n.parsedType
      }), ne;
    }
    return ct(e.data);
  }
}
Qa.create = (t) => new Qa({
  typeName: Z.ZodNull,
  ...ie(t)
});
class yi extends le {
  constructor() {
    super(...arguments), this._any = !0;
  }
  _parse(e) {
    return ct(e.data);
  }
}
yi.create = (t) => new yi({
  typeName: Z.ZodAny,
  ...ie(t)
});
class Ya extends le {
  constructor() {
    super(...arguments), this._unknown = !0;
  }
  _parse(e) {
    return ct(e.data);
  }
}
Ya.create = (t) => new Ya({
  typeName: Z.ZodUnknown,
  ...ie(t)
});
class qt extends le {
  _parse(e) {
    const r = this._getOrReturnCtx(e);
    return K(r, {
      code: U.invalid_type,
      expected: W.never,
      received: r.parsedType
    }), ne;
  }
}
qt.create = (t) => new qt({
  typeName: Z.ZodNever,
  ...ie(t)
});
class Xa extends le {
  _parse(e) {
    if (this._getType(e) !== W.undefined) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.void,
        received: n.parsedType
      }), ne;
    }
    return ct(e.data);
  }
}
Xa.create = (t) => new Xa({
  typeName: Z.ZodVoid,
  ...ie(t)
});
class vt extends le {
  _parse(e) {
    const { ctx: r, status: n } = this._processInputParams(e), s = this._def;
    if (r.parsedType !== W.array)
      return K(r, {
        code: U.invalid_type,
        expected: W.array,
        received: r.parsedType
      }), ne;
    if (s.exactLength !== null) {
      const a = r.data.length > s.exactLength.value, o = r.data.length < s.exactLength.value;
      (a || o) && (K(r, {
        code: a ? U.too_big : U.too_small,
        minimum: o ? s.exactLength.value : void 0,
        maximum: a ? s.exactLength.value : void 0,
        type: "array",
        inclusive: !0,
        exact: !0,
        message: s.exactLength.message
      }), n.dirty());
    }
    if (s.minLength !== null && r.data.length < s.minLength.value && (K(r, {
      code: U.too_small,
      minimum: s.minLength.value,
      type: "array",
      inclusive: !0,
      exact: !1,
      message: s.minLength.message
    }), n.dirty()), s.maxLength !== null && r.data.length > s.maxLength.value && (K(r, {
      code: U.too_big,
      maximum: s.maxLength.value,
      type: "array",
      inclusive: !0,
      exact: !1,
      message: s.maxLength.message
    }), n.dirty()), r.common.async)
      return Promise.all([...r.data].map((a, o) => s.type._parseAsync(new wt(r, a, r.path, o)))).then((a) => We.mergeArray(n, a));
    const i = [...r.data].map((a, o) => s.type._parseSync(new wt(r, a, r.path, o)));
    return We.mergeArray(n, i);
  }
  get element() {
    return this._def.type;
  }
  min(e, r) {
    return new vt({
      ...this._def,
      minLength: { value: e, message: B.toString(r) }
    });
  }
  max(e, r) {
    return new vt({
      ...this._def,
      maxLength: { value: e, message: B.toString(r) }
    });
  }
  length(e, r) {
    return new vt({
      ...this._def,
      exactLength: { value: e, message: B.toString(r) }
    });
  }
  nonempty(e) {
    return this.min(1, e);
  }
}
vt.create = (t, e) => new vt({
  type: t,
  minLength: null,
  maxLength: null,
  exactLength: null,
  typeName: Z.ZodArray,
  ...ie(e)
});
function tr(t) {
  if (t instanceof ze) {
    const e = {};
    for (const r in t.shape) {
      const n = t.shape[r];
      e[r] = It.create(tr(n));
    }
    return new ze({
      ...t._def,
      shape: () => e
    });
  } else return t instanceof vt ? new vt({
    ...t._def,
    type: tr(t.element)
  }) : t instanceof It ? It.create(tr(t.unwrap())) : t instanceof dr ? dr.create(tr(t.unwrap())) : t instanceof Qt ? Qt.create(t.items.map((e) => tr(e))) : t;
}
class ze extends le {
  constructor() {
    super(...arguments), this._cached = null, this.nonstrict = this.passthrough, this.augment = this.extend;
  }
  _getCached() {
    if (this._cached !== null)
      return this._cached;
    const e = this._def.shape(), r = ge.objectKeys(e);
    return this._cached = { shape: e, keys: r }, this._cached;
  }
  _parse(e) {
    if (this._getType(e) !== W.object) {
      const u = this._getOrReturnCtx(e);
      return K(u, {
        code: U.invalid_type,
        expected: W.object,
        received: u.parsedType
      }), ne;
    }
    const { status: n, ctx: s } = this._processInputParams(e), { shape: i, keys: a } = this._getCached(), o = [];
    if (!(this._def.catchall instanceof qt && this._def.unknownKeys === "strip"))
      for (const u in s.data)
        a.includes(u) || o.push(u);
    const c = [];
    for (const u of a) {
      const l = i[u], p = s.data[u];
      c.push({
        key: { status: "valid", value: u },
        value: l._parse(new wt(s, p, s.path, u)),
        alwaysSet: u in s.data
      });
    }
    if (this._def.catchall instanceof qt) {
      const u = this._def.unknownKeys;
      if (u === "passthrough")
        for (const l of o)
          c.push({
            key: { status: "valid", value: l },
            value: { status: "valid", value: s.data[l] }
          });
      else if (u === "strict")
        o.length > 0 && (K(s, {
          code: U.unrecognized_keys,
          keys: o
        }), n.dirty());
      else if (u !== "strip") throw new Error("Internal ZodObject error: invalid unknownKeys value.");
    } else {
      const u = this._def.catchall;
      for (const l of o) {
        const p = s.data[l];
        c.push({
          key: { status: "valid", value: l },
          value: u._parse(
            new wt(s, p, s.path, l)
            //, ctx.child(key), value, getParsedType(value)
          ),
          alwaysSet: l in s.data
        });
      }
    }
    return s.common.async ? Promise.resolve().then(async () => {
      const u = [];
      for (const l of c) {
        const p = await l.key, v = await l.value;
        u.push({
          key: p,
          value: v,
          alwaysSet: l.alwaysSet
        });
      }
      return u;
    }).then((u) => We.mergeObjectSync(n, u)) : We.mergeObjectSync(n, c);
  }
  get shape() {
    return this._def.shape();
  }
  strict(e) {
    return B.errToObj, new ze({
      ...this._def,
      unknownKeys: "strict",
      ...e !== void 0 ? {
        errorMap: (r, n) => {
          var i, a;
          const s = ((a = (i = this._def).errorMap) == null ? void 0 : a.call(i, r, n).message) ?? n.defaultError;
          return r.code === "unrecognized_keys" ? {
            message: B.errToObj(e).message ?? s
          } : {
            message: s
          };
        }
      } : {}
    });
  }
  strip() {
    return new ze({
      ...this._def,
      unknownKeys: "strip"
    });
  }
  passthrough() {
    return new ze({
      ...this._def,
      unknownKeys: "passthrough"
    });
  }
  // const AugmentFactory =
  //   <Def extends ZodObjectDef>(def: Def) =>
  //   <Augmentation extends ZodRawShape>(
  //     augmentation: Augmentation
  //   ): ZodObject<
  //     extendShape<ReturnType<Def["shape"]>, Augmentation>,
  //     Def["unknownKeys"],
  //     Def["catchall"]
  //   > => {
  //     return new ZodObject({
  //       ...def,
  //       shape: () => ({
  //         ...def.shape(),
  //         ...augmentation,
  //       }),
  //     }) as any;
  //   };
  extend(e) {
    return new ze({
      ...this._def,
      shape: () => ({
        ...this._def.shape(),
        ...e
      })
    });
  }
  /**
   * Prior to zod@1.0.12 there was a bug in the
   * inferred type of merged objects. Please
   * upgrade if you are experiencing issues.
   */
  merge(e) {
    return new ze({
      unknownKeys: e._def.unknownKeys,
      catchall: e._def.catchall,
      shape: () => ({
        ...this._def.shape(),
        ...e._def.shape()
      }),
      typeName: Z.ZodObject
    });
  }
  // merge<
  //   Incoming extends AnyZodObject,
  //   Augmentation extends Incoming["shape"],
  //   NewOutput extends {
  //     [k in keyof Augmentation | keyof Output]: k extends keyof Augmentation
  //       ? Augmentation[k]["_output"]
  //       : k extends keyof Output
  //       ? Output[k]
  //       : never;
  //   },
  //   NewInput extends {
  //     [k in keyof Augmentation | keyof Input]: k extends keyof Augmentation
  //       ? Augmentation[k]["_input"]
  //       : k extends keyof Input
  //       ? Input[k]
  //       : never;
  //   }
  // >(
  //   merging: Incoming
  // ): ZodObject<
  //   extendShape<T, ReturnType<Incoming["_def"]["shape"]>>,
  //   Incoming["_def"]["unknownKeys"],
  //   Incoming["_def"]["catchall"],
  //   NewOutput,
  //   NewInput
  // > {
  //   const merged: any = new ZodObject({
  //     unknownKeys: merging._def.unknownKeys,
  //     catchall: merging._def.catchall,
  //     shape: () =>
  //       objectUtil.mergeShapes(this._def.shape(), merging._def.shape()),
  //     typeName: ZodFirstPartyTypeKind.ZodObject,
  //   }) as any;
  //   return merged;
  // }
  setKey(e, r) {
    return this.augment({ [e]: r });
  }
  // merge<Incoming extends AnyZodObject>(
  //   merging: Incoming
  // ): //ZodObject<T & Incoming["_shape"], UnknownKeys, Catchall> = (merging) => {
  // ZodObject<
  //   extendShape<T, ReturnType<Incoming["_def"]["shape"]>>,
  //   Incoming["_def"]["unknownKeys"],
  //   Incoming["_def"]["catchall"]
  // > {
  //   // const mergedShape = objectUtil.mergeShapes(
  //   //   this._def.shape(),
  //   //   merging._def.shape()
  //   // );
  //   const merged: any = new ZodObject({
  //     unknownKeys: merging._def.unknownKeys,
  //     catchall: merging._def.catchall,
  //     shape: () =>
  //       objectUtil.mergeShapes(this._def.shape(), merging._def.shape()),
  //     typeName: ZodFirstPartyTypeKind.ZodObject,
  //   }) as any;
  //   return merged;
  // }
  catchall(e) {
    return new ze({
      ...this._def,
      catchall: e
    });
  }
  pick(e) {
    const r = {};
    for (const n of ge.objectKeys(e))
      e[n] && this.shape[n] && (r[n] = this.shape[n]);
    return new ze({
      ...this._def,
      shape: () => r
    });
  }
  omit(e) {
    const r = {};
    for (const n of ge.objectKeys(this.shape))
      e[n] || (r[n] = this.shape[n]);
    return new ze({
      ...this._def,
      shape: () => r
    });
  }
  /**
   * @deprecated
   */
  deepPartial() {
    return tr(this);
  }
  partial(e) {
    const r = {};
    for (const n of ge.objectKeys(this.shape)) {
      const s = this.shape[n];
      e && !e[n] ? r[n] = s : r[n] = s.optional();
    }
    return new ze({
      ...this._def,
      shape: () => r
    });
  }
  required(e) {
    const r = {};
    for (const n of ge.objectKeys(this.shape))
      if (e && !e[n])
        r[n] = this.shape[n];
      else {
        let i = this.shape[n];
        for (; i instanceof It; )
          i = i._def.innerType;
        r[n] = i;
      }
    return new ze({
      ...this._def,
      shape: () => r
    });
  }
  keyof() {
    return el(ge.objectKeys(this.shape));
  }
}
ze.create = (t, e) => new ze({
  shape: () => t,
  unknownKeys: "strip",
  catchall: qt.create(),
  typeName: Z.ZodObject,
  ...ie(e)
});
ze.strictCreate = (t, e) => new ze({
  shape: () => t,
  unknownKeys: "strict",
  catchall: qt.create(),
  typeName: Z.ZodObject,
  ...ie(e)
});
ze.lazycreate = (t, e) => new ze({
  shape: t,
  unknownKeys: "strip",
  catchall: qt.create(),
  typeName: Z.ZodObject,
  ...ie(e)
});
class Xn extends le {
  _parse(e) {
    const { ctx: r } = this._processInputParams(e), n = this._def.options;
    function s(i) {
      for (const o of i)
        if (o.result.status === "valid")
          return o.result;
      for (const o of i)
        if (o.result.status === "dirty")
          return r.common.issues.push(...o.ctx.common.issues), o.result;
      const a = i.map((o) => new Ot(o.ctx.common.issues));
      return K(r, {
        code: U.invalid_union,
        unionErrors: a
      }), ne;
    }
    if (r.common.async)
      return Promise.all(n.map(async (i) => {
        const a = {
          ...r,
          common: {
            ...r.common,
            issues: []
          },
          parent: null
        };
        return {
          result: await i._parseAsync({
            data: r.data,
            path: r.path,
            parent: a
          }),
          ctx: a
        };
      })).then(s);
    {
      let i;
      const a = [];
      for (const c of n) {
        const u = {
          ...r,
          common: {
            ...r.common,
            issues: []
          },
          parent: null
        }, l = c._parseSync({
          data: r.data,
          path: r.path,
          parent: u
        });
        if (l.status === "valid")
          return l;
        l.status === "dirty" && !i && (i = { result: l, ctx: u }), u.common.issues.length && a.push(u.common.issues);
      }
      if (i)
        return r.common.issues.push(...i.ctx.common.issues), i.result;
      const o = a.map((c) => new Ot(c));
      return K(r, {
        code: U.invalid_union,
        unionErrors: o
      }), ne;
    }
  }
  get options() {
    return this._def.options;
  }
}
Xn.create = (t, e) => new Xn({
  options: t,
  typeName: Z.ZodUnion,
  ...ie(e)
});
function vi(t, e) {
  const r = xt(t), n = xt(e);
  if (t === e)
    return { valid: !0, data: t };
  if (r === W.object && n === W.object) {
    const s = ge.objectKeys(e), i = ge.objectKeys(t).filter((o) => s.indexOf(o) !== -1), a = { ...t, ...e };
    for (const o of i) {
      const c = vi(t[o], e[o]);
      if (!c.valid)
        return { valid: !1 };
      a[o] = c.data;
    }
    return { valid: !0, data: a };
  } else if (r === W.array && n === W.array) {
    if (t.length !== e.length)
      return { valid: !1 };
    const s = [];
    for (let i = 0; i < t.length; i++) {
      const a = t[i], o = e[i], c = vi(a, o);
      if (!c.valid)
        return { valid: !1 };
      s.push(c.data);
    }
    return { valid: !0, data: s };
  } else return r === W.date && n === W.date && +t == +e ? { valid: !0, data: t } : { valid: !1 };
}
class es extends le {
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e), s = (i, a) => {
      if (Ka(i) || Ka(a))
        return ne;
      const o = vi(i.value, a.value);
      return o.valid ? ((Ga(i) || Ga(a)) && r.dirty(), { status: r.value, value: o.data }) : (K(n, {
        code: U.invalid_intersection_types
      }), ne);
    };
    return n.common.async ? Promise.all([
      this._def.left._parseAsync({
        data: n.data,
        path: n.path,
        parent: n
      }),
      this._def.right._parseAsync({
        data: n.data,
        path: n.path,
        parent: n
      })
    ]).then(([i, a]) => s(i, a)) : s(this._def.left._parseSync({
      data: n.data,
      path: n.path,
      parent: n
    }), this._def.right._parseSync({
      data: n.data,
      path: n.path,
      parent: n
    }));
  }
}
es.create = (t, e, r) => new es({
  left: t,
  right: e,
  typeName: Z.ZodIntersection,
  ...ie(r)
});
class Qt extends le {
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e);
    if (n.parsedType !== W.array)
      return K(n, {
        code: U.invalid_type,
        expected: W.array,
        received: n.parsedType
      }), ne;
    if (n.data.length < this._def.items.length)
      return K(n, {
        code: U.too_small,
        minimum: this._def.items.length,
        inclusive: !0,
        exact: !1,
        type: "array"
      }), ne;
    !this._def.rest && n.data.length > this._def.items.length && (K(n, {
      code: U.too_big,
      maximum: this._def.items.length,
      inclusive: !0,
      exact: !1,
      type: "array"
    }), r.dirty());
    const i = [...n.data].map((a, o) => {
      const c = this._def.items[o] || this._def.rest;
      return c ? c._parse(new wt(n, a, n.path, o)) : null;
    }).filter((a) => !!a);
    return n.common.async ? Promise.all(i).then((a) => We.mergeArray(r, a)) : We.mergeArray(r, i);
  }
  get items() {
    return this._def.items;
  }
  rest(e) {
    return new Qt({
      ...this._def,
      rest: e
    });
  }
}
Qt.create = (t, e) => {
  if (!Array.isArray(t))
    throw new Error("You must pass an array of schemas to z.tuple([ ... ])");
  return new Qt({
    items: t,
    typeName: Z.ZodTuple,
    rest: null,
    ...ie(e)
  });
};
class ts extends le {
  get keySchema() {
    return this._def.keyType;
  }
  get valueSchema() {
    return this._def.valueType;
  }
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e);
    if (n.parsedType !== W.object)
      return K(n, {
        code: U.invalid_type,
        expected: W.object,
        received: n.parsedType
      }), ne;
    const s = [], i = this._def.keyType, a = this._def.valueType;
    for (const o in n.data)
      s.push({
        key: i._parse(new wt(n, o, n.path, o)),
        value: a._parse(new wt(n, n.data[o], n.path, o)),
        alwaysSet: o in n.data
      });
    return n.common.async ? We.mergeObjectAsync(r, s) : We.mergeObjectSync(r, s);
  }
  get element() {
    return this._def.valueType;
  }
  static create(e, r, n) {
    return r instanceof le ? new ts({
      keyType: e,
      valueType: r,
      typeName: Z.ZodRecord,
      ...ie(n)
    }) : new ts({
      keyType: Rt.create(),
      valueType: e,
      typeName: Z.ZodRecord,
      ...ie(r)
    });
  }
}
class eo extends le {
  get keySchema() {
    return this._def.keyType;
  }
  get valueSchema() {
    return this._def.valueType;
  }
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e);
    if (n.parsedType !== W.map)
      return K(n, {
        code: U.invalid_type,
        expected: W.map,
        received: n.parsedType
      }), ne;
    const s = this._def.keyType, i = this._def.valueType, a = [...n.data.entries()].map(([o, c], u) => ({
      key: s._parse(new wt(n, o, n.path, [u, "key"])),
      value: i._parse(new wt(n, c, n.path, [u, "value"]))
    }));
    if (n.common.async) {
      const o = /* @__PURE__ */ new Map();
      return Promise.resolve().then(async () => {
        for (const c of a) {
          const u = await c.key, l = await c.value;
          if (u.status === "aborted" || l.status === "aborted")
            return ne;
          (u.status === "dirty" || l.status === "dirty") && r.dirty(), o.set(u.value, l.value);
        }
        return { status: r.value, value: o };
      });
    } else {
      const o = /* @__PURE__ */ new Map();
      for (const c of a) {
        const u = c.key, l = c.value;
        if (u.status === "aborted" || l.status === "aborted")
          return ne;
        (u.status === "dirty" || l.status === "dirty") && r.dirty(), o.set(u.value, l.value);
      }
      return { status: r.value, value: o };
    }
  }
}
eo.create = (t, e, r) => new eo({
  valueType: e,
  keyType: t,
  typeName: Z.ZodMap,
  ...ie(r)
});
class Nr extends le {
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e);
    if (n.parsedType !== W.set)
      return K(n, {
        code: U.invalid_type,
        expected: W.set,
        received: n.parsedType
      }), ne;
    const s = this._def;
    s.minSize !== null && n.data.size < s.minSize.value && (K(n, {
      code: U.too_small,
      minimum: s.minSize.value,
      type: "set",
      inclusive: !0,
      exact: !1,
      message: s.minSize.message
    }), r.dirty()), s.maxSize !== null && n.data.size > s.maxSize.value && (K(n, {
      code: U.too_big,
      maximum: s.maxSize.value,
      type: "set",
      inclusive: !0,
      exact: !1,
      message: s.maxSize.message
    }), r.dirty());
    const i = this._def.valueType;
    function a(c) {
      const u = /* @__PURE__ */ new Set();
      for (const l of c) {
        if (l.status === "aborted")
          return ne;
        l.status === "dirty" && r.dirty(), u.add(l.value);
      }
      return { status: r.value, value: u };
    }
    const o = [...n.data.values()].map((c, u) => i._parse(new wt(n, c, n.path, u)));
    return n.common.async ? Promise.all(o).then((c) => a(c)) : a(o);
  }
  min(e, r) {
    return new Nr({
      ...this._def,
      minSize: { value: e, message: B.toString(r) }
    });
  }
  max(e, r) {
    return new Nr({
      ...this._def,
      maxSize: { value: e, message: B.toString(r) }
    });
  }
  size(e, r) {
    return this.min(e, r).max(e, r);
  }
  nonempty(e) {
    return this.min(1, e);
  }
}
Nr.create = (t, e) => new Nr({
  valueType: t,
  minSize: null,
  maxSize: null,
  typeName: Z.ZodSet,
  ...ie(e)
});
class to extends le {
  get schema() {
    return this._def.getter();
  }
  _parse(e) {
    const { ctx: r } = this._processInputParams(e);
    return this._def.getter()._parse({ data: r.data, path: r.path, parent: r });
  }
}
to.create = (t, e) => new to({
  getter: t,
  typeName: Z.ZodLazy,
  ...ie(e)
});
class ro extends le {
  _parse(e) {
    if (e.data !== this._def.value) {
      const r = this._getOrReturnCtx(e);
      return K(r, {
        received: r.data,
        code: U.invalid_literal,
        expected: this._def.value
      }), ne;
    }
    return { status: "valid", value: e.data };
  }
  get value() {
    return this._def.value;
  }
}
ro.create = (t, e) => new ro({
  value: t,
  typeName: Z.ZodLiteral,
  ...ie(e)
});
function el(t, e) {
  return new ur({
    values: t,
    typeName: Z.ZodEnum,
    ...ie(e)
  });
}
class ur extends le {
  _parse(e) {
    if (typeof e.data != "string") {
      const r = this._getOrReturnCtx(e), n = this._def.values;
      return K(r, {
        expected: ge.joinValues(n),
        received: r.parsedType,
        code: U.invalid_type
      }), ne;
    }
    if (this._cache || (this._cache = new Set(this._def.values)), !this._cache.has(e.data)) {
      const r = this._getOrReturnCtx(e), n = this._def.values;
      return K(r, {
        received: r.data,
        code: U.invalid_enum_value,
        options: n
      }), ne;
    }
    return ct(e.data);
  }
  get options() {
    return this._def.values;
  }
  get enum() {
    const e = {};
    for (const r of this._def.values)
      e[r] = r;
    return e;
  }
  get Values() {
    const e = {};
    for (const r of this._def.values)
      e[r] = r;
    return e;
  }
  get Enum() {
    const e = {};
    for (const r of this._def.values)
      e[r] = r;
    return e;
  }
  extract(e, r = this._def) {
    return ur.create(e, {
      ...this._def,
      ...r
    });
  }
  exclude(e, r = this._def) {
    return ur.create(this.options.filter((n) => !e.includes(n)), {
      ...this._def,
      ...r
    });
  }
}
ur.create = el;
class no extends le {
  _parse(e) {
    const r = ge.getValidEnumValues(this._def.values), n = this._getOrReturnCtx(e);
    if (n.parsedType !== W.string && n.parsedType !== W.number) {
      const s = ge.objectValues(r);
      return K(n, {
        expected: ge.joinValues(s),
        received: n.parsedType,
        code: U.invalid_type
      }), ne;
    }
    if (this._cache || (this._cache = new Set(ge.getValidEnumValues(this._def.values))), !this._cache.has(e.data)) {
      const s = ge.objectValues(r);
      return K(n, {
        received: n.data,
        code: U.invalid_enum_value,
        options: s
      }), ne;
    }
    return ct(e.data);
  }
  get enum() {
    return this._def.values;
  }
}
no.create = (t, e) => new no({
  values: t,
  typeName: Z.ZodNativeEnum,
  ...ie(e)
});
class rs extends le {
  unwrap() {
    return this._def.type;
  }
  _parse(e) {
    const { ctx: r } = this._processInputParams(e);
    if (r.parsedType !== W.promise && r.common.async === !1)
      return K(r, {
        code: U.invalid_type,
        expected: W.promise,
        received: r.parsedType
      }), ne;
    const n = r.parsedType === W.promise ? r.data : Promise.resolve(r.data);
    return ct(n.then((s) => this._def.type.parseAsync(s, {
      path: r.path,
      errorMap: r.common.contextualErrorMap
    })));
  }
}
rs.create = (t, e) => new rs({
  type: t,
  typeName: Z.ZodPromise,
  ...ie(e)
});
class lr extends le {
  innerType() {
    return this._def.schema;
  }
  sourceType() {
    return this._def.schema._def.typeName === Z.ZodEffects ? this._def.schema.sourceType() : this._def.schema;
  }
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e), s = this._def.effect || null, i = {
      addIssue: (a) => {
        K(n, a), a.fatal ? r.abort() : r.dirty();
      },
      get path() {
        return n.path;
      }
    };
    if (i.addIssue = i.addIssue.bind(i), s.type === "preprocess") {
      const a = s.transform(n.data, i);
      if (n.common.async)
        return Promise.resolve(a).then(async (o) => {
          if (r.value === "aborted")
            return ne;
          const c = await this._def.schema._parseAsync({
            data: o,
            path: n.path,
            parent: n
          });
          return c.status === "aborted" ? ne : c.status === "dirty" || r.value === "dirty" ? Er(c.value) : c;
        });
      {
        if (r.value === "aborted")
          return ne;
        const o = this._def.schema._parseSync({
          data: a,
          path: n.path,
          parent: n
        });
        return o.status === "aborted" ? ne : o.status === "dirty" || r.value === "dirty" ? Er(o.value) : o;
      }
    }
    if (s.type === "refinement") {
      const a = (o) => {
        const c = s.refinement(o, i);
        if (n.common.async)
          return Promise.resolve(c);
        if (c instanceof Promise)
          throw new Error("Async refinement encountered during synchronous parse operation. Use .parseAsync instead.");
        return o;
      };
      if (n.common.async === !1) {
        const o = this._def.schema._parseSync({
          data: n.data,
          path: n.path,
          parent: n
        });
        return o.status === "aborted" ? ne : (o.status === "dirty" && r.dirty(), a(o.value), { status: r.value, value: o.value });
      } else
        return this._def.schema._parseAsync({ data: n.data, path: n.path, parent: n }).then((o) => o.status === "aborted" ? ne : (o.status === "dirty" && r.dirty(), a(o.value).then(() => ({ status: r.value, value: o.value }))));
    }
    if (s.type === "transform")
      if (n.common.async === !1) {
        const a = this._def.schema._parseSync({
          data: n.data,
          path: n.path,
          parent: n
        });
        if (!or(a))
          return ne;
        const o = s.transform(a.value, i);
        if (o instanceof Promise)
          throw new Error("Asynchronous transform encountered during synchronous parse operation. Use .parseAsync instead.");
        return { status: r.value, value: o };
      } else
        return this._def.schema._parseAsync({ data: n.data, path: n.path, parent: n }).then((a) => or(a) ? Promise.resolve(s.transform(a.value, i)).then((o) => ({
          status: r.value,
          value: o
        })) : ne);
    ge.assertNever(s);
  }
}
lr.create = (t, e, r) => new lr({
  schema: t,
  typeName: Z.ZodEffects,
  effect: e,
  ...ie(r)
});
lr.createWithPreprocess = (t, e, r) => new lr({
  schema: e,
  effect: { type: "preprocess", transform: t },
  typeName: Z.ZodEffects,
  ...ie(r)
});
class It extends le {
  _parse(e) {
    return this._getType(e) === W.undefined ? ct(void 0) : this._def.innerType._parse(e);
  }
  unwrap() {
    return this._def.innerType;
  }
}
It.create = (t, e) => new It({
  innerType: t,
  typeName: Z.ZodOptional,
  ...ie(e)
});
class dr extends le {
  _parse(e) {
    return this._getType(e) === W.null ? ct(null) : this._def.innerType._parse(e);
  }
  unwrap() {
    return this._def.innerType;
  }
}
dr.create = (t, e) => new dr({
  innerType: t,
  typeName: Z.ZodNullable,
  ...ie(e)
});
class wi extends le {
  _parse(e) {
    const { ctx: r } = this._processInputParams(e);
    let n = r.data;
    return r.parsedType === W.undefined && (n = this._def.defaultValue()), this._def.innerType._parse({
      data: n,
      path: r.path,
      parent: r
    });
  }
  removeDefault() {
    return this._def.innerType;
  }
}
wi.create = (t, e) => new wi({
  innerType: t,
  typeName: Z.ZodDefault,
  defaultValue: typeof e.default == "function" ? e.default : () => e.default,
  ...ie(e)
});
class bi extends le {
  _parse(e) {
    const { ctx: r } = this._processInputParams(e), n = {
      ...r,
      common: {
        ...r.common,
        issues: []
      }
    }, s = this._def.innerType._parse({
      data: n.data,
      path: n.path,
      parent: {
        ...n
      }
    });
    return Qn(s) ? s.then((i) => ({
      status: "valid",
      value: i.status === "valid" ? i.value : this._def.catchValue({
        get error() {
          return new Ot(n.common.issues);
        },
        input: n.data
      })
    })) : {
      status: "valid",
      value: s.status === "valid" ? s.value : this._def.catchValue({
        get error() {
          return new Ot(n.common.issues);
        },
        input: n.data
      })
    };
  }
  removeCatch() {
    return this._def.innerType;
  }
}
bi.create = (t, e) => new bi({
  innerType: t,
  typeName: Z.ZodCatch,
  catchValue: typeof e.catch == "function" ? e.catch : () => e.catch,
  ...ie(e)
});
class so extends le {
  _parse(e) {
    if (this._getType(e) !== W.nan) {
      const n = this._getOrReturnCtx(e);
      return K(n, {
        code: U.invalid_type,
        expected: W.nan,
        received: n.parsedType
      }), ne;
    }
    return { status: "valid", value: e.data };
  }
}
so.create = (t) => new so({
  typeName: Z.ZodNaN,
  ...ie(t)
});
class k_ extends le {
  _parse(e) {
    const { ctx: r } = this._processInputParams(e), n = r.data;
    return this._def.type._parse({
      data: n,
      path: r.path,
      parent: r
    });
  }
  unwrap() {
    return this._def.type;
  }
}
class sa extends le {
  _parse(e) {
    const { status: r, ctx: n } = this._processInputParams(e);
    if (n.common.async)
      return (async () => {
        const i = await this._def.in._parseAsync({
          data: n.data,
          path: n.path,
          parent: n
        });
        return i.status === "aborted" ? ne : i.status === "dirty" ? (r.dirty(), Er(i.value)) : this._def.out._parseAsync({
          data: i.value,
          path: n.path,
          parent: n
        });
      })();
    {
      const s = this._def.in._parseSync({
        data: n.data,
        path: n.path,
        parent: n
      });
      return s.status === "aborted" ? ne : s.status === "dirty" ? (r.dirty(), {
        status: "dirty",
        value: s.value
      }) : this._def.out._parseSync({
        data: s.value,
        path: n.path,
        parent: n
      });
    }
  }
  static create(e, r) {
    return new sa({
      in: e,
      out: r,
      typeName: Z.ZodPipeline
    });
  }
}
class Si extends le {
  _parse(e) {
    const r = this._def.innerType._parse(e), n = (s) => (or(s) && (s.value = Object.freeze(s.value)), s);
    return Qn(r) ? r.then((s) => n(s)) : n(r);
  }
  unwrap() {
    return this._def.innerType;
  }
}
Si.create = (t, e) => new Si({
  innerType: t,
  typeName: Z.ZodReadonly,
  ...ie(e)
});
var Z;
(function(t) {
  t.ZodString = "ZodString", t.ZodNumber = "ZodNumber", t.ZodNaN = "ZodNaN", t.ZodBigInt = "ZodBigInt", t.ZodBoolean = "ZodBoolean", t.ZodDate = "ZodDate", t.ZodSymbol = "ZodSymbol", t.ZodUndefined = "ZodUndefined", t.ZodNull = "ZodNull", t.ZodAny = "ZodAny", t.ZodUnknown = "ZodUnknown", t.ZodNever = "ZodNever", t.ZodVoid = "ZodVoid", t.ZodArray = "ZodArray", t.ZodObject = "ZodObject", t.ZodUnion = "ZodUnion", t.ZodDiscriminatedUnion = "ZodDiscriminatedUnion", t.ZodIntersection = "ZodIntersection", t.ZodTuple = "ZodTuple", t.ZodRecord = "ZodRecord", t.ZodMap = "ZodMap", t.ZodSet = "ZodSet", t.ZodFunction = "ZodFunction", t.ZodLazy = "ZodLazy", t.ZodLiteral = "ZodLiteral", t.ZodEnum = "ZodEnum", t.ZodEffects = "ZodEffects", t.ZodNativeEnum = "ZodNativeEnum", t.ZodOptional = "ZodOptional", t.ZodNullable = "ZodNullable", t.ZodDefault = "ZodDefault", t.ZodCatch = "ZodCatch", t.ZodPromise = "ZodPromise", t.ZodBranded = "ZodBranded", t.ZodPipeline = "ZodPipeline", t.ZodReadonly = "ZodReadonly";
})(Z || (Z = {}));
const Ps = Rt.create, $_ = cr.create, E_ = _i.create, T_ = yi.create;
qt.create;
const R_ = vt.create, tl = ze.create;
Xn.create;
es.create;
Qt.create;
const I_ = ts.create, P_ = ur.create;
rs.create;
It.create;
dr.create;
const O_ = /* @__PURE__ */ z("ZodMiniType", (t, e) => {
  if (!t._zod)
    throw new Error("Uninitialized schema in ZodMiniType.");
  Ee.init(t, e), t.def = e, t.parse = (r, n) => Ul(t, r, n, { callee: t.parse }), t.safeParse = (r, n) => Ii(t, r, n), t.parseAsync = async (r, n) => Ll(t, r, n, { callee: t.parseAsync }), t.safeParseAsync = async (r, n) => Pi(t, r, n), t.check = (...r) => t.clone(
    {
      ...e,
      checks: [
        ...e.checks ?? [],
        ...r.map((n) => typeof n == "function" ? { _zod: { check: n, def: { check: "custom" }, onattach: [] } } : n)
      ]
    }
    // { parent: true }
  ), t.clone = (r, n) => Mt(t, r, n), t.brand = () => t, t.register = ((r, n) => (r.add(t, n), t));
}), C_ = /* @__PURE__ */ z("ZodMiniObject", (t, e) => {
  Yc.init(t, e), O_.init(t, e), $e(t, "shape", () => e.shape);
});
function io(t, e) {
  const r = {
    type: "object",
    get shape() {
      return xr(this, "shape", { ...t }), this.shape;
    },
    ...Q(e)
  };
  return new C_(r);
}
function bt(t) {
  return !!t._zod;
}
function sr(t) {
  const e = Object.values(t);
  if (e.length === 0)
    return io({});
  const r = e.every(bt), n = e.every((s) => !bt(s));
  if (r)
    return io(t);
  if (n)
    return tl(t);
  throw new Error("Mixed Zod versions detected in object shape.");
}
function Rr(t, e) {
  return bt(t) ? Ii(t, e) : t.safeParse(e);
}
async function Os(t, e) {
  return bt(t) ? await Pi(t, e) : await t.safeParseAsync(e);
}
function Fr(t) {
  var r, n;
  if (!t)
    return;
  let e;
  if (bt(t) ? e = (n = (r = t._zod) == null ? void 0 : r.def) == null ? void 0 : n.shape : e = t.shape, !!e) {
    if (typeof e == "function")
      try {
        return e();
      } catch {
        return;
      }
    return e;
  }
}
function _r(t) {
  var e;
  if (t) {
    if (typeof t == "object") {
      const r = t, n = t;
      if (!r._def && !n._zod) {
        const s = Object.values(t);
        if (s.length > 0 && s.every((i) => typeof i == "object" && i !== null && (i._def !== void 0 || i._zod !== void 0 || typeof i.parse == "function")))
          return sr(t);
      }
    }
    if (bt(t)) {
      const n = (e = t._zod) == null ? void 0 : e.def;
      if (n && (n.type === "object" || n.shape !== void 0))
        return t;
    } else if (t.shape !== void 0)
      return t;
  }
}
function Cs(t) {
  if (t && typeof t == "object") {
    if ("message" in t && typeof t.message == "string")
      return t.message;
    if ("issues" in t && Array.isArray(t.issues) && t.issues.length > 0) {
      const e = t.issues[0];
      if (e && typeof e == "object" && "message" in e)
        return String(e.message);
    }
    try {
      return JSON.stringify(t);
    } catch {
      return String(t);
    }
  }
  return String(t);
}
function A_(t) {
  return t.description;
}
function N_(t) {
  var r, n, s;
  if (bt(t))
    return ((n = (r = t._zod) == null ? void 0 : r.def) == null ? void 0 : n.type) === "optional";
  const e = t;
  return typeof t.isOptional == "function" ? t.isOptional() : ((s = e._def) == null ? void 0 : s.typeName) === "ZodOptional";
}
function rl(t) {
  var s;
  if (bt(t)) {
    const a = (s = t._zod) == null ? void 0 : s.def;
    if (a) {
      if (a.value !== void 0)
        return a.value;
      if (Array.isArray(a.values) && a.values.length > 0)
        return a.values[0];
    }
  }
  const r = t._def;
  if (r) {
    if (r.value !== void 0)
      return r.value;
    if (Array.isArray(r.values) && r.values.length > 0)
      return r.values[0];
  }
  const n = t.value;
  if (n !== void 0)
    return n;
}
function Lt(t) {
  return t === "completed" || t === "failed" || t === "cancelled";
}
const x_ = Symbol("Let zodToJsonSchema decide on which parser to use"), ao = {
  name: void 0,
  $refStrategy: "root",
  basePath: ["#"],
  effectStrategy: "input",
  pipeStrategy: "all",
  dateStrategy: "format:date-time",
  mapStrategy: "entries",
  removeAdditionalStrategy: "passthrough",
  allowedAdditionalProperties: !0,
  rejectedAdditionalProperties: !1,
  definitionPath: "definitions",
  target: "jsonSchema7",
  strictUnions: !1,
  definitions: {},
  errorMessages: !1,
  markdownDescription: !1,
  patternStrategy: "escape",
  applyRegexFlags: !1,
  emailStrategy: "format:email",
  base64Strategy: "contentEncoding:base64",
  nameStrategy: "ref",
  openAiAnyTypeName: "OpenAiAnyType"
}, j_ = (t) => typeof t == "string" ? {
  ...ao,
  name: t
} : {
  ...ao,
  ...t
}, z_ = (t) => {
  const e = j_(t), r = e.name !== void 0 ? [...e.basePath, e.definitionPath, e.name] : e.basePath;
  return {
    ...e,
    flags: { hasReferencedOpenAiAnyType: !1 },
    currentPath: r,
    propertyPath: void 0,
    seen: new Map(Object.entries(e.definitions).map(([n, s]) => [
      s._def,
      {
        def: s._def,
        path: [...e.basePath, e.definitionPath, n],
        // Resolution of references will be forced even though seen, so it's ok that the schema is undefined here for now.
        jsonSchema: void 0
      }
    ]))
  };
};
function nl(t, e, r, n) {
  n != null && n.errorMessages && r && (t.errorMessage = {
    ...t.errorMessage,
    [e]: r
  });
}
function be(t, e, r, n, s) {
  t[e] = r, nl(t, e, n, s);
}
const sl = (t, e) => {
  let r = 0;
  for (; r < t.length && r < e.length && t[r] === e[r]; r++)
    ;
  return [(t.length - r).toString(), ...e.slice(r)].join("/");
};
function Xe(t) {
  if (t.target !== "openAi")
    return {};
  const e = [
    ...t.basePath,
    t.definitionPath,
    t.openAiAnyTypeName
  ];
  return t.flags.hasReferencedOpenAiAnyType = !0, {
    $ref: t.$refStrategy === "relative" ? sl(e, t.currentPath) : e.join("/")
  };
}
function q_(t, e) {
  var n, s, i;
  const r = {
    type: "array"
  };
  return (n = t.type) != null && n._def && ((i = (s = t.type) == null ? void 0 : s._def) == null ? void 0 : i.typeName) !== Z.ZodAny && (r.items = ye(t.type._def, {
    ...e,
    currentPath: [...e.currentPath, "items"]
  })), t.minLength && be(r, "minItems", t.minLength.value, t.minLength.message, e), t.maxLength && be(r, "maxItems", t.maxLength.value, t.maxLength.message, e), t.exactLength && (be(r, "minItems", t.exactLength.value, t.exactLength.message, e), be(r, "maxItems", t.exactLength.value, t.exactLength.message, e)), r;
}
function M_(t, e) {
  const r = {
    type: "integer",
    format: "int64"
  };
  if (!t.checks)
    return r;
  for (const n of t.checks)
    switch (n.kind) {
      case "min":
        e.target === "jsonSchema7" ? n.inclusive ? be(r, "minimum", n.value, n.message, e) : be(r, "exclusiveMinimum", n.value, n.message, e) : (n.inclusive || (r.exclusiveMinimum = !0), be(r, "minimum", n.value, n.message, e));
        break;
      case "max":
        e.target === "jsonSchema7" ? n.inclusive ? be(r, "maximum", n.value, n.message, e) : be(r, "exclusiveMaximum", n.value, n.message, e) : (n.inclusive || (r.exclusiveMaximum = !0), be(r, "maximum", n.value, n.message, e));
        break;
      case "multipleOf":
        be(r, "multipleOf", n.value, n.message, e);
        break;
    }
  return r;
}
function D_() {
  return {
    type: "boolean"
  };
}
function il(t, e) {
  return ye(t.type._def, e);
}
const U_ = (t, e) => ye(t.innerType._def, e);
function al(t, e, r) {
  const n = r ?? e.dateStrategy;
  if (Array.isArray(n))
    return {
      anyOf: n.map((s, i) => al(t, e, s))
    };
  switch (n) {
    case "string":
    case "format:date-time":
      return {
        type: "string",
        format: "date-time"
      };
    case "format:date":
      return {
        type: "string",
        format: "date"
      };
    case "integer":
      return L_(t, e);
  }
}
const L_ = (t, e) => {
  const r = {
    type: "integer",
    format: "unix-time"
  };
  if (e.target === "openApi3")
    return r;
  for (const n of t.checks)
    switch (n.kind) {
      case "min":
        be(
          r,
          "minimum",
          n.value,
          // This is in milliseconds
          n.message,
          e
        );
        break;
      case "max":
        be(
          r,
          "maximum",
          n.value,
          // This is in milliseconds
          n.message,
          e
        );
        break;
    }
  return r;
};
function Z_(t, e) {
  return {
    ...ye(t.innerType._def, e),
    default: t.defaultValue()
  };
}
function F_(t, e) {
  return e.effectStrategy === "input" ? ye(t.schema._def, e) : Xe(e);
}
function H_(t) {
  return {
    type: "string",
    enum: Array.from(t.values)
  };
}
const V_ = (t) => "type" in t && t.type === "string" ? !1 : "allOf" in t;
function K_(t, e) {
  const r = [
    ye(t.left._def, {
      ...e,
      currentPath: [...e.currentPath, "allOf", "0"]
    }),
    ye(t.right._def, {
      ...e,
      currentPath: [...e.currentPath, "allOf", "1"]
    })
  ].filter((i) => !!i);
  let n = e.target === "jsonSchema2019-09" ? { unevaluatedProperties: !1 } : void 0;
  const s = [];
  return r.forEach((i) => {
    if (V_(i))
      s.push(...i.allOf), i.unevaluatedProperties === void 0 && (n = void 0);
    else {
      let a = i;
      if ("additionalProperties" in i && i.additionalProperties === !1) {
        const { additionalProperties: o, ...c } = i;
        a = c;
      } else
        n = void 0;
      s.push(a);
    }
  }), s.length ? {
    allOf: s,
    ...n
  } : void 0;
}
function G_(t, e) {
  const r = typeof t.value;
  return r !== "bigint" && r !== "number" && r !== "boolean" && r !== "string" ? {
    type: Array.isArray(t.value) ? "array" : "object"
  } : e.target === "openApi3" ? {
    type: r === "bigint" ? "integer" : r,
    enum: [t.value]
  } : {
    type: r === "bigint" ? "integer" : r,
    const: t.value
  };
}
let As;
const lt = {
  /**
   * `c` was changed to `[cC]` to replicate /i flag
   */
  cuid: /^[cC][^\s-]{8,}$/,
  cuid2: /^[0-9a-z]+$/,
  ulid: /^[0-9A-HJKMNP-TV-Z]{26}$/,
  /**
   * `a-z` was added to replicate /i flag
   */
  email: /^(?!\.)(?!.*\.\.)([a-zA-Z0-9_'+\-\.]*)[a-zA-Z0-9_+-]@([a-zA-Z0-9][a-zA-Z0-9\-]*\.)+[a-zA-Z]{2,}$/,
  /**
   * Constructed a valid Unicode RegExp
   *
   * Lazily instantiate since this type of regex isn't supported
   * in all envs (e.g. React Native).
   *
   * See:
   * https://github.com/colinhacks/zod/issues/2433
   * Fix in Zod:
   * https://github.com/colinhacks/zod/commit/9340fd51e48576a75adc919bff65dbc4a5d4c99b
   */
  emoji: () => (As === void 0 && (As = RegExp("^(\\p{Extended_Pictographic}|\\p{Emoji_Component})+$", "u")), As),
  /**
   * Unused
   */
  uuid: /^[0-9a-fA-F]{8}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{12}$/,
  /**
   * Unused
   */
  ipv4: /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])$/,
  ipv4Cidr: /^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])\/(3[0-2]|[12]?[0-9])$/,
  /**
   * Unused
   */
  ipv6: /^(([a-f0-9]{1,4}:){7}|::([a-f0-9]{1,4}:){0,6}|([a-f0-9]{1,4}:){1}:([a-f0-9]{1,4}:){0,5}|([a-f0-9]{1,4}:){2}:([a-f0-9]{1,4}:){0,4}|([a-f0-9]{1,4}:){3}:([a-f0-9]{1,4}:){0,3}|([a-f0-9]{1,4}:){4}:([a-f0-9]{1,4}:){0,2}|([a-f0-9]{1,4}:){5}:([a-f0-9]{1,4}:){0,1})([a-f0-9]{1,4}|(((25[0-5])|(2[0-4][0-9])|(1[0-9]{2})|([0-9]{1,2}))\.){3}((25[0-5])|(2[0-4][0-9])|(1[0-9]{2})|([0-9]{1,2})))$/,
  ipv6Cidr: /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))\/(12[0-8]|1[01][0-9]|[1-9]?[0-9])$/,
  base64: /^([0-9a-zA-Z+/]{4})*(([0-9a-zA-Z+/]{2}==)|([0-9a-zA-Z+/]{3}=))?$/,
  base64url: /^([0-9a-zA-Z-_]{4})*(([0-9a-zA-Z-_]{2}(==)?)|([0-9a-zA-Z-_]{3}(=)?))?$/,
  nanoid: /^[a-zA-Z0-9_-]{21}$/,
  jwt: /^[A-Za-z0-9-_]+\.[A-Za-z0-9-_]+\.[A-Za-z0-9-_]*$/
};
function ol(t, e) {
  const r = {
    type: "string"
  };
  if (t.checks)
    for (const n of t.checks)
      switch (n.kind) {
        case "min":
          be(r, "minLength", typeof r.minLength == "number" ? Math.max(r.minLength, n.value) : n.value, n.message, e);
          break;
        case "max":
          be(r, "maxLength", typeof r.maxLength == "number" ? Math.min(r.maxLength, n.value) : n.value, n.message, e);
          break;
        case "email":
          switch (e.emailStrategy) {
            case "format:email":
              dt(r, "email", n.message, e);
              break;
            case "format:idn-email":
              dt(r, "idn-email", n.message, e);
              break;
            case "pattern:zod":
              Ke(r, lt.email, n.message, e);
              break;
          }
          break;
        case "url":
          dt(r, "uri", n.message, e);
          break;
        case "uuid":
          dt(r, "uuid", n.message, e);
          break;
        case "regex":
          Ke(r, n.regex, n.message, e);
          break;
        case "cuid":
          Ke(r, lt.cuid, n.message, e);
          break;
        case "cuid2":
          Ke(r, lt.cuid2, n.message, e);
          break;
        case "startsWith":
          Ke(r, RegExp(`^${Ns(n.value, e)}`), n.message, e);
          break;
        case "endsWith":
          Ke(r, RegExp(`${Ns(n.value, e)}$`), n.message, e);
          break;
        case "datetime":
          dt(r, "date-time", n.message, e);
          break;
        case "date":
          dt(r, "date", n.message, e);
          break;
        case "time":
          dt(r, "time", n.message, e);
          break;
        case "duration":
          dt(r, "duration", n.message, e);
          break;
        case "length":
          be(r, "minLength", typeof r.minLength == "number" ? Math.max(r.minLength, n.value) : n.value, n.message, e), be(r, "maxLength", typeof r.maxLength == "number" ? Math.min(r.maxLength, n.value) : n.value, n.message, e);
          break;
        case "includes": {
          Ke(r, RegExp(Ns(n.value, e)), n.message, e);
          break;
        }
        case "ip": {
          n.version !== "v6" && dt(r, "ipv4", n.message, e), n.version !== "v4" && dt(r, "ipv6", n.message, e);
          break;
        }
        case "base64url":
          Ke(r, lt.base64url, n.message, e);
          break;
        case "jwt":
          Ke(r, lt.jwt, n.message, e);
          break;
        case "cidr": {
          n.version !== "v6" && Ke(r, lt.ipv4Cidr, n.message, e), n.version !== "v4" && Ke(r, lt.ipv6Cidr, n.message, e);
          break;
        }
        case "emoji":
          Ke(r, lt.emoji(), n.message, e);
          break;
        case "ulid": {
          Ke(r, lt.ulid, n.message, e);
          break;
        }
        case "base64": {
          switch (e.base64Strategy) {
            case "format:binary": {
              dt(r, "binary", n.message, e);
              break;
            }
            case "contentEncoding:base64": {
              be(r, "contentEncoding", "base64", n.message, e);
              break;
            }
            case "pattern:zod": {
              Ke(r, lt.base64, n.message, e);
              break;
            }
          }
          break;
        }
        case "nanoid":
          Ke(r, lt.nanoid, n.message, e);
      }
  return r;
}
function Ns(t, e) {
  return e.patternStrategy === "escape" ? J_(t) : t;
}
const W_ = new Set("ABCDEFGHIJKLMNOPQRSTUVXYZabcdefghijklmnopqrstuvxyz0123456789");
function J_(t) {
  let e = "";
  for (let r = 0; r < t.length; r++)
    W_.has(t[r]) || (e += "\\"), e += t[r];
  return e;
}
function dt(t, e, r, n) {
  var s;
  t.format || (s = t.anyOf) != null && s.some((i) => i.format) ? (t.anyOf || (t.anyOf = []), t.format && (t.anyOf.push({
    format: t.format,
    ...t.errorMessage && n.errorMessages && {
      errorMessage: { format: t.errorMessage.format }
    }
  }), delete t.format, t.errorMessage && (delete t.errorMessage.format, Object.keys(t.errorMessage).length === 0 && delete t.errorMessage)), t.anyOf.push({
    format: e,
    ...r && n.errorMessages && { errorMessage: { format: r } }
  })) : be(t, "format", e, r, n);
}
function Ke(t, e, r, n) {
  var s;
  t.pattern || (s = t.allOf) != null && s.some((i) => i.pattern) ? (t.allOf || (t.allOf = []), t.pattern && (t.allOf.push({
    pattern: t.pattern,
    ...t.errorMessage && n.errorMessages && {
      errorMessage: { pattern: t.errorMessage.pattern }
    }
  }), delete t.pattern, t.errorMessage && (delete t.errorMessage.pattern, Object.keys(t.errorMessage).length === 0 && delete t.errorMessage)), t.allOf.push({
    pattern: oo(e, n),
    ...r && n.errorMessages && { errorMessage: { pattern: r } }
  })) : be(t, "pattern", oo(e, n), r, n);
}
function oo(t, e) {
  var c;
  if (!e.applyRegexFlags || !t.flags)
    return t.source;
  const r = {
    i: t.flags.includes("i"),
    m: t.flags.includes("m"),
    s: t.flags.includes("s")
    // `.` matches newlines
  }, n = r.i ? t.source.toLowerCase() : t.source;
  let s = "", i = !1, a = !1, o = !1;
  for (let u = 0; u < n.length; u++) {
    if (i) {
      s += n[u], i = !1;
      continue;
    }
    if (r.i) {
      if (a) {
        if (n[u].match(/[a-z]/)) {
          o ? (s += n[u], s += `${n[u - 2]}-${n[u]}`.toUpperCase(), o = !1) : n[u + 1] === "-" && ((c = n[u + 2]) != null && c.match(/[a-z]/)) ? (s += n[u], o = !0) : s += `${n[u]}${n[u].toUpperCase()}`;
          continue;
        }
      } else if (n[u].match(/[a-z]/)) {
        s += `[${n[u]}${n[u].toUpperCase()}]`;
        continue;
      }
    }
    if (r.m) {
      if (n[u] === "^") {
        s += `(^|(?<=[\r
]))`;
        continue;
      } else if (n[u] === "$") {
        s += `($|(?=[\r
]))`;
        continue;
      }
    }
    if (r.s && n[u] === ".") {
      s += a ? `${n[u]}\r
` : `[${n[u]}\r
]`;
      continue;
    }
    s += n[u], n[u] === "\\" ? i = !0 : a && n[u] === "]" ? a = !1 : !a && n[u] === "[" && (a = !0);
  }
  try {
    new RegExp(s);
  } catch {
    return console.warn(`Could not convert regex pattern at ${e.currentPath.join("/")} to a flag-independent form! Falling back to the flag-ignorant source`), t.source;
  }
  return s;
}
function cl(t, e) {
  var n, s, i, a, o, c;
  if (e.target === "openAi" && console.warn("Warning: OpenAI may not support records in schemas! Try an array of key-value pairs instead."), e.target === "openApi3" && ((n = t.keyType) == null ? void 0 : n._def.typeName) === Z.ZodEnum)
    return {
      type: "object",
      required: t.keyType._def.values,
      properties: t.keyType._def.values.reduce((u, l) => ({
        ...u,
        [l]: ye(t.valueType._def, {
          ...e,
          currentPath: [...e.currentPath, "properties", l]
        }) ?? Xe(e)
      }), {}),
      additionalProperties: e.rejectedAdditionalProperties
    };
  const r = {
    type: "object",
    additionalProperties: ye(t.valueType._def, {
      ...e,
      currentPath: [...e.currentPath, "additionalProperties"]
    }) ?? e.allowedAdditionalProperties
  };
  if (e.target === "openApi3")
    return r;
  if (((s = t.keyType) == null ? void 0 : s._def.typeName) === Z.ZodString && ((i = t.keyType._def.checks) != null && i.length)) {
    const { type: u, ...l } = ol(t.keyType._def, e);
    return {
      ...r,
      propertyNames: l
    };
  } else {
    if (((a = t.keyType) == null ? void 0 : a._def.typeName) === Z.ZodEnum)
      return {
        ...r,
        propertyNames: {
          enum: t.keyType._def.values
        }
      };
    if (((o = t.keyType) == null ? void 0 : o._def.typeName) === Z.ZodBranded && t.keyType._def.type._def.typeName === Z.ZodString && ((c = t.keyType._def.type._def.checks) != null && c.length)) {
      const { type: u, ...l } = il(t.keyType._def, e);
      return {
        ...r,
        propertyNames: l
      };
    }
  }
  return r;
}
function B_(t, e) {
  if (e.mapStrategy === "record")
    return cl(t, e);
  const r = ye(t.keyType._def, {
    ...e,
    currentPath: [...e.currentPath, "items", "items", "0"]
  }) || Xe(e), n = ye(t.valueType._def, {
    ...e,
    currentPath: [...e.currentPath, "items", "items", "1"]
  }) || Xe(e);
  return {
    type: "array",
    maxItems: 125,
    items: {
      type: "array",
      items: [r, n],
      minItems: 2,
      maxItems: 2
    }
  };
}
function Q_(t) {
  const e = t.values, n = Object.keys(t.values).filter((i) => typeof e[e[i]] != "number").map((i) => e[i]), s = Array.from(new Set(n.map((i) => typeof i)));
  return {
    type: s.length === 1 ? s[0] === "string" ? "string" : "number" : ["string", "number"],
    enum: n
  };
}
function Y_(t) {
  return t.target === "openAi" ? void 0 : {
    not: Xe({
      ...t,
      currentPath: [...t.currentPath, "not"]
    })
  };
}
function X_(t) {
  return t.target === "openApi3" ? {
    enum: ["null"],
    nullable: !0
  } : {
    type: "null"
  };
}
const ns = {
  ZodString: "string",
  ZodNumber: "number",
  ZodBigInt: "integer",
  ZodBoolean: "boolean",
  ZodNull: "null"
};
function ey(t, e) {
  if (e.target === "openApi3")
    return co(t, e);
  const r = t.options instanceof Map ? Array.from(t.options.values()) : t.options;
  if (r.every((n) => n._def.typeName in ns && (!n._def.checks || !n._def.checks.length))) {
    const n = r.reduce((s, i) => {
      const a = ns[i._def.typeName];
      return a && !s.includes(a) ? [...s, a] : s;
    }, []);
    return {
      type: n.length > 1 ? n : n[0]
    };
  } else if (r.every((n) => n._def.typeName === "ZodLiteral" && !n.description)) {
    const n = r.reduce((s, i) => {
      const a = typeof i._def.value;
      switch (a) {
        case "string":
        case "number":
        case "boolean":
          return [...s, a];
        case "bigint":
          return [...s, "integer"];
        case "object":
          if (i._def.value === null)
            return [...s, "null"];
        case "symbol":
        case "undefined":
        case "function":
        default:
          return s;
      }
    }, []);
    if (n.length === r.length) {
      const s = n.filter((i, a, o) => o.indexOf(i) === a);
      return {
        type: s.length > 1 ? s : s[0],
        enum: r.reduce((i, a) => i.includes(a._def.value) ? i : [...i, a._def.value], [])
      };
    }
  } else if (r.every((n) => n._def.typeName === "ZodEnum"))
    return {
      type: "string",
      enum: r.reduce((n, s) => [
        ...n,
        ...s._def.values.filter((i) => !n.includes(i))
      ], [])
    };
  return co(t, e);
}
const co = (t, e) => {
  const r = (t.options instanceof Map ? Array.from(t.options.values()) : t.options).map((n, s) => ye(n._def, {
    ...e,
    currentPath: [...e.currentPath, "anyOf", `${s}`]
  })).filter((n) => !!n && (!e.strictUnions || typeof n == "object" && Object.keys(n).length > 0));
  return r.length ? { anyOf: r } : void 0;
};
function ty(t, e) {
  if (["ZodString", "ZodNumber", "ZodBigInt", "ZodBoolean", "ZodNull"].includes(t.innerType._def.typeName) && (!t.innerType._def.checks || !t.innerType._def.checks.length))
    return e.target === "openApi3" ? {
      type: ns[t.innerType._def.typeName],
      nullable: !0
    } : {
      type: [
        ns[t.innerType._def.typeName],
        "null"
      ]
    };
  if (e.target === "openApi3") {
    const n = ye(t.innerType._def, {
      ...e,
      currentPath: [...e.currentPath]
    });
    return n && "$ref" in n ? { allOf: [n], nullable: !0 } : n && { ...n, nullable: !0 };
  }
  const r = ye(t.innerType._def, {
    ...e,
    currentPath: [...e.currentPath, "anyOf", "0"]
  });
  return r && { anyOf: [r, { type: "null" }] };
}
function ry(t, e) {
  const r = {
    type: "number"
  };
  if (!t.checks)
    return r;
  for (const n of t.checks)
    switch (n.kind) {
      case "int":
        r.type = "integer", nl(r, "type", n.message, e);
        break;
      case "min":
        e.target === "jsonSchema7" ? n.inclusive ? be(r, "minimum", n.value, n.message, e) : be(r, "exclusiveMinimum", n.value, n.message, e) : (n.inclusive || (r.exclusiveMinimum = !0), be(r, "minimum", n.value, n.message, e));
        break;
      case "max":
        e.target === "jsonSchema7" ? n.inclusive ? be(r, "maximum", n.value, n.message, e) : be(r, "exclusiveMaximum", n.value, n.message, e) : (n.inclusive || (r.exclusiveMaximum = !0), be(r, "maximum", n.value, n.message, e));
        break;
      case "multipleOf":
        be(r, "multipleOf", n.value, n.message, e);
        break;
    }
  return r;
}
function ny(t, e) {
  const r = e.target === "openAi", n = {
    type: "object",
    properties: {}
  }, s = [], i = t.shape();
  for (const o in i) {
    let c = i[o];
    if (c === void 0 || c._def === void 0)
      continue;
    let u = iy(c);
    u && r && (c._def.typeName === "ZodOptional" && (c = c._def.innerType), c.isNullable() || (c = c.nullable()), u = !1);
    const l = ye(c._def, {
      ...e,
      currentPath: [...e.currentPath, "properties", o],
      propertyPath: [...e.currentPath, "properties", o]
    });
    l !== void 0 && (n.properties[o] = l, u || s.push(o));
  }
  s.length && (n.required = s);
  const a = sy(t, e);
  return a !== void 0 && (n.additionalProperties = a), n;
}
function sy(t, e) {
  if (t.catchall._def.typeName !== "ZodNever")
    return ye(t.catchall._def, {
      ...e,
      currentPath: [...e.currentPath, "additionalProperties"]
    });
  switch (t.unknownKeys) {
    case "passthrough":
      return e.allowedAdditionalProperties;
    case "strict":
      return e.rejectedAdditionalProperties;
    case "strip":
      return e.removeAdditionalStrategy === "strict" ? e.allowedAdditionalProperties : e.rejectedAdditionalProperties;
  }
}
function iy(t) {
  try {
    return t.isOptional();
  } catch {
    return !0;
  }
}
const ay = (t, e) => {
  var n;
  if (e.currentPath.toString() === ((n = e.propertyPath) == null ? void 0 : n.toString()))
    return ye(t.innerType._def, e);
  const r = ye(t.innerType._def, {
    ...e,
    currentPath: [...e.currentPath, "anyOf", "1"]
  });
  return r ? {
    anyOf: [
      {
        not: Xe(e)
      },
      r
    ]
  } : Xe(e);
}, oy = (t, e) => {
  if (e.pipeStrategy === "input")
    return ye(t.in._def, e);
  if (e.pipeStrategy === "output")
    return ye(t.out._def, e);
  const r = ye(t.in._def, {
    ...e,
    currentPath: [...e.currentPath, "allOf", "0"]
  }), n = ye(t.out._def, {
    ...e,
    currentPath: [...e.currentPath, "allOf", r ? "1" : "0"]
  });
  return {
    allOf: [r, n].filter((s) => s !== void 0)
  };
};
function cy(t, e) {
  return ye(t.type._def, e);
}
function uy(t, e) {
  const n = {
    type: "array",
    uniqueItems: !0,
    items: ye(t.valueType._def, {
      ...e,
      currentPath: [...e.currentPath, "items"]
    })
  };
  return t.minSize && be(n, "minItems", t.minSize.value, t.minSize.message, e), t.maxSize && be(n, "maxItems", t.maxSize.value, t.maxSize.message, e), n;
}
function ly(t, e) {
  return t.rest ? {
    type: "array",
    minItems: t.items.length,
    items: t.items.map((r, n) => ye(r._def, {
      ...e,
      currentPath: [...e.currentPath, "items", `${n}`]
    })).reduce((r, n) => n === void 0 ? r : [...r, n], []),
    additionalItems: ye(t.rest._def, {
      ...e,
      currentPath: [...e.currentPath, "additionalItems"]
    })
  } : {
    type: "array",
    minItems: t.items.length,
    maxItems: t.items.length,
    items: t.items.map((r, n) => ye(r._def, {
      ...e,
      currentPath: [...e.currentPath, "items", `${n}`]
    })).reduce((r, n) => n === void 0 ? r : [...r, n], [])
  };
}
function dy(t) {
  return {
    not: Xe(t)
  };
}
function hy(t) {
  return Xe(t);
}
const fy = (t, e) => ye(t.innerType._def, e), py = (t, e, r) => {
  switch (e) {
    case Z.ZodString:
      return ol(t, r);
    case Z.ZodNumber:
      return ry(t, r);
    case Z.ZodObject:
      return ny(t, r);
    case Z.ZodBigInt:
      return M_(t, r);
    case Z.ZodBoolean:
      return D_();
    case Z.ZodDate:
      return al(t, r);
    case Z.ZodUndefined:
      return dy(r);
    case Z.ZodNull:
      return X_(r);
    case Z.ZodArray:
      return q_(t, r);
    case Z.ZodUnion:
    case Z.ZodDiscriminatedUnion:
      return ey(t, r);
    case Z.ZodIntersection:
      return K_(t, r);
    case Z.ZodTuple:
      return ly(t, r);
    case Z.ZodRecord:
      return cl(t, r);
    case Z.ZodLiteral:
      return G_(t, r);
    case Z.ZodEnum:
      return H_(t);
    case Z.ZodNativeEnum:
      return Q_(t);
    case Z.ZodNullable:
      return ty(t, r);
    case Z.ZodOptional:
      return ay(t, r);
    case Z.ZodMap:
      return B_(t, r);
    case Z.ZodSet:
      return uy(t, r);
    case Z.ZodLazy:
      return () => t.getter()._def;
    case Z.ZodPromise:
      return cy(t, r);
    case Z.ZodNaN:
    case Z.ZodNever:
      return Y_(r);
    case Z.ZodEffects:
      return F_(t, r);
    case Z.ZodAny:
      return Xe(r);
    case Z.ZodUnknown:
      return hy(r);
    case Z.ZodDefault:
      return Z_(t, r);
    case Z.ZodBranded:
      return il(t, r);
    case Z.ZodReadonly:
      return fy(t, r);
    case Z.ZodCatch:
      return U_(t, r);
    case Z.ZodPipeline:
      return oy(t, r);
    case Z.ZodFunction:
    case Z.ZodVoid:
    case Z.ZodSymbol:
      return;
    default:
      return /* @__PURE__ */ ((n) => {
      })();
  }
};
function ye(t, e, r = !1) {
  var o;
  const n = e.seen.get(t);
  if (e.override) {
    const c = (o = e.override) == null ? void 0 : o.call(e, t, e, n, r);
    if (c !== x_)
      return c;
  }
  if (n && !r) {
    const c = my(n, e);
    if (c !== void 0)
      return c;
  }
  const s = { def: t, path: e.currentPath, jsonSchema: void 0 };
  e.seen.set(t, s);
  const i = py(t, t.typeName, e), a = typeof i == "function" ? ye(i(), e) : i;
  if (a && gy(t, e, a), e.postProcess) {
    const c = e.postProcess(a, t, e);
    return s.jsonSchema = a, c;
  }
  return s.jsonSchema = a, a;
}
const my = (t, e) => {
  switch (e.$refStrategy) {
    case "root":
      return { $ref: t.path.join("/") };
    case "relative":
      return { $ref: sl(e.currentPath, t.path) };
    case "none":
    case "seen":
      return t.path.length < e.currentPath.length && t.path.every((r, n) => e.currentPath[n] === r) ? (console.warn(`Recursive reference detected at ${e.currentPath.join("/")}! Defaulting to any`), Xe(e)) : e.$refStrategy === "seen" ? Xe(e) : void 0;
  }
}, gy = (t, e, r) => (t.description && (r.description = t.description, e.markdownDescription && (r.markdownDescription = t.description)), r), _y = (t, e) => {
  const r = z_(e);
  let n = typeof e == "object" && e.definitions ? Object.entries(e.definitions).reduce((c, [u, l]) => ({
    ...c,
    [u]: ye(l._def, {
      ...r,
      currentPath: [...r.basePath, r.definitionPath, u]
    }, !0) ?? Xe(r)
  }), {}) : void 0;
  const s = typeof e == "string" ? e : (e == null ? void 0 : e.nameStrategy) === "title" || e == null ? void 0 : e.name, i = ye(t._def, s === void 0 ? r : {
    ...r,
    currentPath: [...r.basePath, r.definitionPath, s]
  }, !1) ?? Xe(r), a = typeof e == "object" && e.name !== void 0 && e.nameStrategy === "title" ? e.name : void 0;
  a !== void 0 && (i.title = a), r.flags.hasReferencedOpenAiAnyType && (n || (n = {}), n[r.openAiAnyTypeName] || (n[r.openAiAnyTypeName] = {
    // Skipping "object" as no properties can be defined and additionalProperties must be "false"
    type: ["string", "number", "integer", "boolean", "array", "null"],
    items: {
      $ref: r.$refStrategy === "relative" ? "1" : [
        ...r.basePath,
        r.definitionPath,
        r.openAiAnyTypeName
      ].join("/")
    }
  }));
  const o = s === void 0 ? n ? {
    ...i,
    [r.definitionPath]: n
  } : i : {
    $ref: [
      ...r.$refStrategy === "relative" ? [] : r.basePath,
      r.definitionPath,
      s
    ].join("/"),
    [r.definitionPath]: {
      ...n,
      [s]: i
    }
  };
  return r.target === "jsonSchema7" ? o.$schema = "http://json-schema.org/draft-07/schema#" : (r.target === "jsonSchema2019-09" || r.target === "openAi") && (o.$schema = "https://json-schema.org/draft/2019-09/schema#"), r.target === "openAi" && ("anyOf" in o || "oneOf" in o || "allOf" in o || "type" in o && Array.isArray(o.type)) && console.warn("Warning: OpenAI may not support schemas with unions as roots! Try wrapping it in an object property."), o;
};
function yy(t) {
  return !t || t === "jsonSchema7" || t === "draft-7" ? "draft-7" : t === "jsonSchema2019-09" || t === "draft-2020-12" ? "draft-2020-12" : "draft-7";
}
function uo(t, e) {
  return bt(t) ? mf(t, {
    target: yy(e == null ? void 0 : e.target),
    io: (e == null ? void 0 : e.pipeStrategy) ?? "input"
  }) : _y(t, {
    strictUnions: (e == null ? void 0 : e.strictUnions) ?? !0,
    pipeStrategy: (e == null ? void 0 : e.pipeStrategy) ?? "input"
  });
}
function lo(t) {
  const e = Fr(t), r = e == null ? void 0 : e.method;
  if (!r)
    throw new Error("Schema is missing a method literal");
  const n = rl(r);
  if (typeof n != "string")
    throw new Error("Schema method literal must be a string");
  return n;
}
function ho(t, e) {
  const r = Rr(t, e);
  if (!r.success)
    throw r.error;
  return r.data;
}
const vy = 6e4;
class wy {
  constructor(e) {
    this._options = e, this._requestMessageId = 0, this._requestHandlers = /* @__PURE__ */ new Map(), this._requestHandlerAbortControllers = /* @__PURE__ */ new Map(), this._notificationHandlers = /* @__PURE__ */ new Map(), this._responseHandlers = /* @__PURE__ */ new Map(), this._progressHandlers = /* @__PURE__ */ new Map(), this._timeoutInfo = /* @__PURE__ */ new Map(), this._pendingDebouncedNotifications = /* @__PURE__ */ new Set(), this._taskProgressTokens = /* @__PURE__ */ new Map(), this._requestResolvers = /* @__PURE__ */ new Map(), this.setNotificationHandler(zi, (r) => {
      this._oncancel(r);
    }), this.setNotificationHandler(Mi, (r) => {
      this._onprogress(r);
    }), this.setRequestHandler(
      qi,
      // Automatic pong by default.
      (r) => ({})
    ), this._taskStore = e == null ? void 0 : e.taskStore, this._taskMessageQueue = e == null ? void 0 : e.taskMessageQueue, this._taskStore && (this.setRequestHandler(Di, async (r, n) => {
      const s = await this._taskStore.getTask(r.params.taskId, n.sessionId);
      if (!s)
        throw new X(re.InvalidParams, "Failed to retrieve task: Task not found");
      return {
        ...s
      };
    }), this.setRequestHandler(Li, async (r, n) => {
      const s = async () => {
        var o;
        const i = r.params.taskId;
        if (this._taskMessageQueue) {
          let c;
          for (; c = await this._taskMessageQueue.dequeue(i, n.sessionId); ) {
            if (c.type === "response" || c.type === "error") {
              const u = c.message, l = u.id, p = this._requestResolvers.get(l);
              if (p)
                if (this._requestResolvers.delete(l), c.type === "response")
                  p(u);
                else {
                  const v = u, w = new X(v.error.code, v.error.message, v.error.data);
                  p(w);
                }
              else {
                const v = c.type === "response" ? "Response" : "Error";
                this._onerror(new Error(`${v} handler missing for request ${l}`));
              }
              continue;
            }
            await ((o = this._transport) == null ? void 0 : o.send(c.message, { relatedRequestId: n.requestId }));
          }
        }
        const a = await this._taskStore.getTask(i, n.sessionId);
        if (!a)
          throw new X(re.InvalidParams, `Task not found: ${i}`);
        if (!Lt(a.status))
          return await this._waitForTaskUpdate(i, n.signal), await s();
        if (Lt(a.status)) {
          const c = await this._taskStore.getTaskResult(i, n.sessionId);
          return this._clearTaskQueue(i), {
            ...c,
            _meta: {
              ...c._meta,
              [Vt]: {
                taskId: i
              }
            }
          };
        }
        return await s();
      };
      return await s();
    }), this.setRequestHandler(Zi, async (r, n) => {
      var s;
      try {
        const { tasks: i, nextCursor: a } = await this._taskStore.listTasks((s = r.params) == null ? void 0 : s.cursor, n.sessionId);
        return {
          tasks: i,
          nextCursor: a,
          _meta: {}
        };
      } catch (i) {
        throw new X(re.InvalidParams, `Failed to list tasks: ${i instanceof Error ? i.message : String(i)}`);
      }
    }), this.setRequestHandler(Hi, async (r, n) => {
      try {
        const s = await this._taskStore.getTask(r.params.taskId, n.sessionId);
        if (!s)
          throw new X(re.InvalidParams, `Task not found: ${r.params.taskId}`);
        if (Lt(s.status))
          throw new X(re.InvalidParams, `Cannot cancel task in terminal status: ${s.status}`);
        await this._taskStore.updateTaskStatus(r.params.taskId, "cancelled", "Client cancelled task execution.", n.sessionId), this._clearTaskQueue(r.params.taskId);
        const i = await this._taskStore.getTask(r.params.taskId, n.sessionId);
        if (!i)
          throw new X(re.InvalidParams, `Task not found after cancellation: ${r.params.taskId}`);
        return {
          _meta: {},
          ...i
        };
      } catch (s) {
        throw s instanceof X ? s : new X(re.InvalidRequest, `Failed to cancel task: ${s instanceof Error ? s.message : String(s)}`);
      }
    }));
  }
  async _oncancel(e) {
    if (!e.params.requestId)
      return;
    const r = this._requestHandlerAbortControllers.get(e.params.requestId);
    r == null || r.abort(e.params.reason);
  }
  _setupTimeout(e, r, n, s, i = !1) {
    this._timeoutInfo.set(e, {
      timeoutId: setTimeout(s, r),
      startTime: Date.now(),
      timeout: r,
      maxTotalTimeout: n,
      resetTimeoutOnProgress: i,
      onTimeout: s
    });
  }
  _resetTimeout(e) {
    const r = this._timeoutInfo.get(e);
    if (!r)
      return !1;
    const n = Date.now() - r.startTime;
    if (r.maxTotalTimeout && n >= r.maxTotalTimeout)
      throw this._timeoutInfo.delete(e), X.fromError(re.RequestTimeout, "Maximum total timeout exceeded", {
        maxTotalTimeout: r.maxTotalTimeout,
        totalElapsed: n
      });
    return clearTimeout(r.timeoutId), r.timeoutId = setTimeout(r.onTimeout, r.timeout), !0;
  }
  _cleanupTimeout(e) {
    const r = this._timeoutInfo.get(e);
    r && (clearTimeout(r.timeoutId), this._timeoutInfo.delete(e));
  }
  /**
   * Attaches to the given transport, starts it, and starts listening for messages.
   *
   * The Protocol object assumes ownership of the Transport, replacing any callbacks that have already been set, and expects that it is the only user of the Transport instance going forward.
   */
  async connect(e) {
    var i, a, o;
    if (this._transport)
      throw new Error("Already connected to a transport. Call close() before connecting to a new transport, or use a separate Protocol instance per connection.");
    this._transport = e;
    const r = (i = this.transport) == null ? void 0 : i.onclose;
    this._transport.onclose = () => {
      r == null || r(), this._onclose();
    };
    const n = (a = this.transport) == null ? void 0 : a.onerror;
    this._transport.onerror = (c) => {
      n == null || n(c), this._onerror(c);
    };
    const s = (o = this._transport) == null ? void 0 : o.onmessage;
    this._transport.onmessage = (c, u) => {
      s == null || s(c, u), Gr(c) || Ep(c) ? this._onresponse(c) : Ia(c) ? this._onrequest(c, u) : $p(c) ? this._onnotification(c) : this._onerror(new Error(`Unknown message type: ${JSON.stringify(c)}`));
    }, await this._transport.start();
  }
  _onclose() {
    var n;
    const e = this._responseHandlers;
    this._responseHandlers = /* @__PURE__ */ new Map(), this._progressHandlers.clear(), this._taskProgressTokens.clear(), this._pendingDebouncedNotifications.clear();
    for (const s of this._timeoutInfo.values())
      clearTimeout(s.timeoutId);
    this._timeoutInfo.clear();
    for (const s of this._requestHandlerAbortControllers.values())
      s.abort();
    this._requestHandlerAbortControllers.clear();
    const r = X.fromError(re.ConnectionClosed, "Connection closed");
    this._transport = void 0, (n = this.onclose) == null || n.call(this);
    for (const s of e.values())
      s(r);
  }
  _onerror(e) {
    var r;
    (r = this.onerror) == null || r.call(this, e);
  }
  _onnotification(e) {
    const r = this._notificationHandlers.get(e.method) ?? this.fallbackNotificationHandler;
    r !== void 0 && Promise.resolve().then(() => r(e)).catch((n) => this._onerror(new Error(`Uncaught error in notification handler: ${n}`)));
  }
  _onrequest(e, r) {
    var l, p, v, w;
    const n = this._requestHandlers.get(e.method) ?? this.fallbackRequestHandler, s = this._transport, i = (v = (p = (l = e.params) == null ? void 0 : l._meta) == null ? void 0 : p[Vt]) == null ? void 0 : v.taskId;
    if (n === void 0) {
      const b = {
        jsonrpc: "2.0",
        id: e.id,
        error: {
          code: re.MethodNotFound,
          message: "Method not found"
        }
      };
      i && this._taskMessageQueue ? this._enqueueTaskMessage(i, {
        type: "error",
        message: b,
        timestamp: Date.now()
      }, s == null ? void 0 : s.sessionId).catch((E) => this._onerror(new Error(`Failed to enqueue error response: ${E}`))) : s == null || s.send(b).catch((E) => this._onerror(new Error(`Failed to send an error response: ${E}`)));
      return;
    }
    const a = new AbortController();
    this._requestHandlerAbortControllers.set(e.id, a);
    const o = kp(e.params) ? e.params.task : void 0, c = this._taskStore ? this.requestTaskStore(e, s == null ? void 0 : s.sessionId) : void 0, u = {
      signal: a.signal,
      sessionId: s == null ? void 0 : s.sessionId,
      _meta: (w = e.params) == null ? void 0 : w._meta,
      sendNotification: async (b) => {
        if (a.signal.aborted)
          return;
        const E = { relatedRequestId: e.id };
        i && (E.relatedTask = { taskId: i }), await this.notification(b, E);
      },
      sendRequest: async (b, E, _) => {
        var g;
        if (a.signal.aborted)
          throw new X(re.ConnectionClosed, "Request was cancelled");
        const m = { ..._, relatedRequestId: e.id };
        i && !m.relatedTask && (m.relatedTask = { taskId: i });
        const h = ((g = m.relatedTask) == null ? void 0 : g.taskId) ?? i;
        return h && c && await c.updateTaskStatus(h, "input_required"), await this.request(b, E, m);
      },
      authInfo: r == null ? void 0 : r.authInfo,
      requestId: e.id,
      requestInfo: r == null ? void 0 : r.requestInfo,
      taskId: i,
      taskStore: c,
      taskRequestedTtl: o == null ? void 0 : o.ttl,
      closeSSEStream: r == null ? void 0 : r.closeSSEStream,
      closeStandaloneSSEStream: r == null ? void 0 : r.closeStandaloneSSEStream
    };
    Promise.resolve().then(() => {
      o && this.assertTaskHandlerCapability(e.method);
    }).then(() => n(e, u)).then(async (b) => {
      if (a.signal.aborted)
        return;
      const E = {
        result: b,
        jsonrpc: "2.0",
        id: e.id
      };
      i && this._taskMessageQueue ? await this._enqueueTaskMessage(i, {
        type: "response",
        message: E,
        timestamp: Date.now()
      }, s == null ? void 0 : s.sessionId) : await (s == null ? void 0 : s.send(E));
    }, async (b) => {
      if (a.signal.aborted)
        return;
      const E = {
        jsonrpc: "2.0",
        id: e.id,
        error: {
          code: Number.isSafeInteger(b.code) ? b.code : re.InternalError,
          message: b.message ?? "Internal error",
          ...b.data !== void 0 && { data: b.data }
        }
      };
      i && this._taskMessageQueue ? await this._enqueueTaskMessage(i, {
        type: "error",
        message: E,
        timestamp: Date.now()
      }, s == null ? void 0 : s.sessionId) : await (s == null ? void 0 : s.send(E));
    }).catch((b) => this._onerror(new Error(`Failed to send response: ${b}`))).finally(() => {
      this._requestHandlerAbortControllers.get(e.id) === a && this._requestHandlerAbortControllers.delete(e.id);
    });
  }
  _onprogress(e) {
    const { progressToken: r, ...n } = e.params, s = Number(r), i = this._progressHandlers.get(s);
    if (!i) {
      this._onerror(new Error(`Received a progress notification for an unknown token: ${JSON.stringify(e)}`));
      return;
    }
    const a = this._responseHandlers.get(s), o = this._timeoutInfo.get(s);
    if (o && a && o.resetTimeoutOnProgress)
      try {
        this._resetTimeout(s);
      } catch (c) {
        this._responseHandlers.delete(s), this._progressHandlers.delete(s), this._cleanupTimeout(s), a(c);
        return;
      }
    i(n);
  }
  _onresponse(e) {
    const r = Number(e.id), n = this._requestResolvers.get(r);
    if (n) {
      if (this._requestResolvers.delete(r), Gr(e))
        n(e);
      else {
        const a = new X(e.error.code, e.error.message, e.error.data);
        n(a);
      }
      return;
    }
    const s = this._responseHandlers.get(r);
    if (s === void 0) {
      this._onerror(new Error(`Received a response for an unknown message ID: ${JSON.stringify(e)}`));
      return;
    }
    this._responseHandlers.delete(r), this._cleanupTimeout(r);
    let i = !1;
    if (Gr(e) && e.result && typeof e.result == "object") {
      const a = e.result;
      if (a.task && typeof a.task == "object") {
        const o = a.task;
        typeof o.taskId == "string" && (i = !0, this._taskProgressTokens.set(o.taskId, r));
      }
    }
    if (i || this._progressHandlers.delete(r), Gr(e))
      s(e);
    else {
      const a = X.fromError(e.error.code, e.error.message, e.error.data);
      s(a);
    }
  }
  get transport() {
    return this._transport;
  }
  /**
   * Closes the connection.
   */
  async close() {
    var e;
    await ((e = this._transport) == null ? void 0 : e.close());
  }
  /**
   * Sends a request and returns an AsyncGenerator that yields response messages.
   * The generator is guaranteed to end with either a 'result' or 'error' message.
   *
   * @example
   * ```typescript
   * const stream = protocol.requestStream(request, resultSchema, options);
   * for await (const message of stream) {
   *   switch (message.type) {
   *     case 'taskCreated':
   *       console.log('Task created:', message.task.taskId);
   *       break;
   *     case 'taskStatus':
   *       console.log('Task status:', message.task.status);
   *       break;
   *     case 'result':
   *       console.log('Final result:', message.result);
   *       break;
   *     case 'error':
   *       console.error('Error:', message.error);
   *       break;
   *   }
   * }
   * ```
   *
   * @experimental Use `client.experimental.tasks.requestStream()` to access this method.
   */
  async *requestStream(e, r, n) {
    var a, o;
    const { task: s } = n ?? {};
    if (!s) {
      try {
        yield { type: "result", result: await this.request(e, r, n) };
      } catch (c) {
        yield {
          type: "error",
          error: c instanceof X ? c : new X(re.InternalError, String(c))
        };
      }
      return;
    }
    let i;
    try {
      const c = await this.request(e, ds, n);
      if (c.task)
        i = c.task.taskId, yield { type: "taskCreated", task: c.task };
      else
        throw new X(re.InternalError, "Task creation did not return a task");
      for (; ; ) {
        const u = await this.getTask({ taskId: i }, n);
        if (yield { type: "taskStatus", task: u }, Lt(u.status)) {
          u.status === "completed" ? yield { type: "result", result: await this.getTaskResult({ taskId: i }, r, n) } : u.status === "failed" ? yield {
            type: "error",
            error: new X(re.InternalError, `Task ${i} failed`)
          } : u.status === "cancelled" && (yield {
            type: "error",
            error: new X(re.InternalError, `Task ${i} was cancelled`)
          });
          return;
        }
        if (u.status === "input_required") {
          yield { type: "result", result: await this.getTaskResult({ taskId: i }, r, n) };
          return;
        }
        const l = u.pollInterval ?? ((a = this._options) == null ? void 0 : a.defaultTaskPollInterval) ?? 1e3;
        await new Promise((p) => setTimeout(p, l)), (o = n == null ? void 0 : n.signal) == null || o.throwIfAborted();
      }
    } catch (c) {
      yield {
        type: "error",
        error: c instanceof X ? c : new X(re.InternalError, String(c))
      };
    }
  }
  /**
   * Sends a request and waits for a response.
   *
   * Do not use this method to emit notifications! Use notification() instead.
   */
  request(e, r, n) {
    const { relatedRequestId: s, resumptionToken: i, onresumptiontoken: a, task: o, relatedTask: c } = n ?? {};
    return new Promise((u, l) => {
      var h, g, $, d, f;
      const p = (y) => {
        l(y);
      };
      if (!this._transport) {
        p(new Error("Not connected"));
        return;
      }
      if (((h = this._options) == null ? void 0 : h.enforceStrictCapabilities) === !0)
        try {
          this.assertCapabilityForMethod(e.method), o && this.assertTaskCapability(e.method);
        } catch (y) {
          p(y);
          return;
        }
      (g = n == null ? void 0 : n.signal) == null || g.throwIfAborted();
      const v = this._requestMessageId++, w = {
        ...e,
        jsonrpc: "2.0",
        id: v
      };
      n != null && n.onprogress && (this._progressHandlers.set(v, n.onprogress), w.params = {
        ...e.params,
        _meta: {
          ...(($ = e.params) == null ? void 0 : $._meta) || {},
          progressToken: v
        }
      }), o && (w.params = {
        ...w.params,
        task: o
      }), c && (w.params = {
        ...w.params,
        _meta: {
          ...((d = w.params) == null ? void 0 : d._meta) || {},
          [Vt]: c
        }
      });
      const b = (y) => {
        var I;
        this._responseHandlers.delete(v), this._progressHandlers.delete(v), this._cleanupTimeout(v), (I = this._transport) == null || I.send({
          jsonrpc: "2.0",
          method: "notifications/cancelled",
          params: {
            requestId: v,
            reason: String(y)
          }
        }, { relatedRequestId: s, resumptionToken: i, onresumptiontoken: a }).catch((N) => this._onerror(new Error(`Failed to send cancellation: ${N}`)));
        const k = y instanceof X ? y : new X(re.RequestTimeout, String(y));
        l(k);
      };
      this._responseHandlers.set(v, (y) => {
        var k;
        if (!((k = n == null ? void 0 : n.signal) != null && k.aborted)) {
          if (y instanceof Error)
            return l(y);
          try {
            const I = Rr(r, y.result);
            I.success ? u(I.data) : l(I.error);
          } catch (I) {
            l(I);
          }
        }
      }), (f = n == null ? void 0 : n.signal) == null || f.addEventListener("abort", () => {
        var y;
        b((y = n == null ? void 0 : n.signal) == null ? void 0 : y.reason);
      });
      const E = (n == null ? void 0 : n.timeout) ?? vy, _ = () => b(X.fromError(re.RequestTimeout, "Request timed out", { timeout: E }));
      this._setupTimeout(v, E, n == null ? void 0 : n.maxTotalTimeout, _, (n == null ? void 0 : n.resetTimeoutOnProgress) ?? !1);
      const m = c == null ? void 0 : c.taskId;
      if (m) {
        const y = (k) => {
          const I = this._responseHandlers.get(v);
          I ? I(k) : this._onerror(new Error(`Response handler missing for side-channeled request ${v}`));
        };
        this._requestResolvers.set(v, y), this._enqueueTaskMessage(m, {
          type: "request",
          message: w,
          timestamp: Date.now()
        }).catch((k) => {
          this._cleanupTimeout(v), l(k);
        });
      } else
        this._transport.send(w, { relatedRequestId: s, resumptionToken: i, onresumptiontoken: a }).catch((y) => {
          this._cleanupTimeout(v), l(y);
        });
    });
  }
  /**
   * Gets the current status of a task.
   *
   * @experimental Use `client.experimental.tasks.getTask()` to access this method.
   */
  async getTask(e, r) {
    return this.request({ method: "tasks/get", params: e }, Ui, r);
  }
  /**
   * Retrieves the result of a completed task.
   *
   * @experimental Use `client.experimental.tasks.getTaskResult()` to access this method.
   */
  async getTaskResult(e, r, n) {
    return this.request({ method: "tasks/result", params: e }, r, n);
  }
  /**
   * Lists tasks, optionally starting from a pagination cursor.
   *
   * @experimental Use `client.experimental.tasks.listTasks()` to access this method.
   */
  async listTasks(e, r) {
    return this.request({ method: "tasks/list", params: e }, Fi, r);
  }
  /**
   * Cancels a specific task.
   *
   * @experimental Use `client.experimental.tasks.cancelTask()` to access this method.
   */
  async cancelTask(e, r) {
    return this.request({ method: "tasks/cancel", params: e }, Zp, r);
  }
  /**
   * Emits a notification, which is a one-way message that does not expect a response.
   */
  async notification(e, r) {
    var o, c, u, l;
    if (!this._transport)
      throw new Error("Not connected");
    this.assertNotificationCapability(e.method);
    const n = (o = r == null ? void 0 : r.relatedTask) == null ? void 0 : o.taskId;
    if (n) {
      const p = {
        ...e,
        jsonrpc: "2.0",
        params: {
          ...e.params,
          _meta: {
            ...((c = e.params) == null ? void 0 : c._meta) || {},
            [Vt]: r.relatedTask
          }
        }
      };
      await this._enqueueTaskMessage(n, {
        type: "notification",
        message: p,
        timestamp: Date.now()
      });
      return;
    }
    if ((((u = this._options) == null ? void 0 : u.debouncedNotificationMethods) ?? []).includes(e.method) && !e.params && !(r != null && r.relatedRequestId) && !(r != null && r.relatedTask)) {
      if (this._pendingDebouncedNotifications.has(e.method))
        return;
      this._pendingDebouncedNotifications.add(e.method), Promise.resolve().then(() => {
        var v, w;
        if (this._pendingDebouncedNotifications.delete(e.method), !this._transport)
          return;
        let p = {
          ...e,
          jsonrpc: "2.0"
        };
        r != null && r.relatedTask && (p = {
          ...p,
          params: {
            ...p.params,
            _meta: {
              ...((v = p.params) == null ? void 0 : v._meta) || {},
              [Vt]: r.relatedTask
            }
          }
        }), (w = this._transport) == null || w.send(p, r).catch((b) => this._onerror(b));
      });
      return;
    }
    let a = {
      ...e,
      jsonrpc: "2.0"
    };
    r != null && r.relatedTask && (a = {
      ...a,
      params: {
        ...a.params,
        _meta: {
          ...((l = a.params) == null ? void 0 : l._meta) || {},
          [Vt]: r.relatedTask
        }
      }
    }), await this._transport.send(a, r);
  }
  /**
   * Registers a handler to invoke when this protocol object receives a request with the given method.
   *
   * Note that this will replace any previous request handler for the same method.
   */
  setRequestHandler(e, r) {
    const n = lo(e);
    this.assertRequestHandlerCapability(n), this._requestHandlers.set(n, (s, i) => {
      const a = ho(e, s);
      return Promise.resolve(r(a, i));
    });
  }
  /**
   * Removes the request handler for the given method.
   */
  removeRequestHandler(e) {
    this._requestHandlers.delete(e);
  }
  /**
   * Asserts that a request handler has not already been set for the given method, in preparation for a new one being automatically installed.
   */
  assertCanSetRequestHandler(e) {
    if (this._requestHandlers.has(e))
      throw new Error(`A request handler for ${e} already exists, which would be overridden`);
  }
  /**
   * Registers a handler to invoke when this protocol object receives a notification with the given method.
   *
   * Note that this will replace any previous notification handler for the same method.
   */
  setNotificationHandler(e, r) {
    const n = lo(e);
    this._notificationHandlers.set(n, (s) => {
      const i = ho(e, s);
      return Promise.resolve(r(i));
    });
  }
  /**
   * Removes the notification handler for the given method.
   */
  removeNotificationHandler(e) {
    this._notificationHandlers.delete(e);
  }
  /**
   * Cleans up the progress handler associated with a task.
   * This should be called when a task reaches a terminal status.
   */
  _cleanupTaskProgressHandler(e) {
    const r = this._taskProgressTokens.get(e);
    r !== void 0 && (this._progressHandlers.delete(r), this._taskProgressTokens.delete(e));
  }
  /**
   * Enqueues a task-related message for side-channel delivery via tasks/result.
   * @param taskId The task ID to associate the message with
   * @param message The message to enqueue
   * @param sessionId Optional session ID for binding the operation to a specific session
   * @throws Error if taskStore is not configured or if enqueue fails (e.g., queue overflow)
   *
   * Note: If enqueue fails, it's the TaskMessageQueue implementation's responsibility to handle
   * the error appropriately (e.g., by failing the task, logging, etc.). The Protocol layer
   * simply propagates the error.
   */
  async _enqueueTaskMessage(e, r, n) {
    var i;
    if (!this._taskStore || !this._taskMessageQueue)
      throw new Error("Cannot enqueue task message: taskStore and taskMessageQueue are not configured");
    const s = (i = this._options) == null ? void 0 : i.maxTaskQueueSize;
    await this._taskMessageQueue.enqueue(e, r, n, s);
  }
  /**
   * Clears the message queue for a task and rejects any pending request resolvers.
   * @param taskId The task ID whose queue should be cleared
   * @param sessionId Optional session ID for binding the operation to a specific session
   */
  async _clearTaskQueue(e, r) {
    if (this._taskMessageQueue) {
      const n = await this._taskMessageQueue.dequeueAll(e, r);
      for (const s of n)
        if (s.type === "request" && Ia(s.message)) {
          const i = s.message.id, a = this._requestResolvers.get(i);
          a ? (a(new X(re.InternalError, "Task cancelled or completed")), this._requestResolvers.delete(i)) : this._onerror(new Error(`Resolver missing for request ${i} during task ${e} cleanup`));
        }
    }
  }
  /**
   * Waits for a task update (new messages or status change) with abort signal support.
   * Uses polling to check for updates at the task's configured poll interval.
   * @param taskId The task ID to wait for
   * @param signal Abort signal to cancel the wait
   * @returns Promise that resolves when an update occurs or rejects if aborted
   */
  async _waitForTaskUpdate(e, r) {
    var s, i;
    let n = ((s = this._options) == null ? void 0 : s.defaultTaskPollInterval) ?? 1e3;
    try {
      const a = await ((i = this._taskStore) == null ? void 0 : i.getTask(e));
      a != null && a.pollInterval && (n = a.pollInterval);
    } catch {
    }
    return new Promise((a, o) => {
      if (r.aborted) {
        o(new X(re.InvalidRequest, "Request cancelled"));
        return;
      }
      const c = setTimeout(a, n);
      r.addEventListener("abort", () => {
        clearTimeout(c), o(new X(re.InvalidRequest, "Request cancelled"));
      }, { once: !0 });
    });
  }
  requestTaskStore(e, r) {
    const n = this._taskStore;
    if (!n)
      throw new Error("No task store configured");
    return {
      createTask: async (s) => {
        if (!e)
          throw new Error("No request provided");
        return await n.createTask(s, e.id, {
          method: e.method,
          params: e.params
        }, r);
      },
      getTask: async (s) => {
        const i = await n.getTask(s, r);
        if (!i)
          throw new X(re.InvalidParams, "Failed to retrieve task: Task not found");
        return i;
      },
      storeTaskResult: async (s, i, a) => {
        await n.storeTaskResult(s, i, a, r);
        const o = await n.getTask(s, r);
        if (o) {
          const c = Ln.parse({
            method: "notifications/tasks/status",
            params: o
          });
          await this.notification(c), Lt(o.status) && this._cleanupTaskProgressHandler(s);
        }
      },
      getTaskResult: (s) => n.getTaskResult(s, r),
      updateTaskStatus: async (s, i, a) => {
        const o = await n.getTask(s, r);
        if (!o)
          throw new X(re.InvalidParams, `Task "${s}" not found - it may have been cleaned up`);
        if (Lt(o.status))
          throw new X(re.InvalidParams, `Cannot update task "${s}" from terminal status "${o.status}" to "${i}". Terminal states (completed, failed, cancelled) cannot transition to other states.`);
        await n.updateTaskStatus(s, i, a, r);
        const c = await n.getTask(s, r);
        if (c) {
          const u = Ln.parse({
            method: "notifications/tasks/status",
            params: c
          });
          await this.notification(u), Lt(c.status) && this._cleanupTaskProgressHandler(s);
        }
      },
      listTasks: (s) => n.listTasks(s, r)
    };
  }
}
function fo(t) {
  return t !== null && typeof t == "object" && !Array.isArray(t);
}
function by(t, e) {
  const r = { ...t };
  for (const n in e) {
    const s = n, i = e[s];
    if (i === void 0)
      continue;
    const a = r[s];
    fo(a) && fo(i) ? r[s] = { ...a, ...i } : r[s] = i;
  }
  return r;
}
function ul(t) {
  return t && t.__esModule && Object.prototype.hasOwnProperty.call(t, "default") ? t.default : t;
}
var Br = { exports: {} }, xs = {}, $t = {}, Zt = {}, js = {}, zs = {}, qs = {}, po;
function ss() {
  return po || (po = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.regexpCode = t.getEsmExportName = t.getProperty = t.safeStringify = t.stringify = t.strConcat = t.addCodeArg = t.str = t._ = t.nil = t._Code = t.Name = t.IDENTIFIER = t._CodeOrName = void 0;
    class e {
    }
    t._CodeOrName = e, t.IDENTIFIER = /^[a-z$_][a-z$_0-9]*$/i;
    class r extends e {
      constructor(h) {
        if (super(), !t.IDENTIFIER.test(h))
          throw new Error("CodeGen: name must be a valid identifier");
        this.str = h;
      }
      toString() {
        return this.str;
      }
      emptyStr() {
        return !1;
      }
      get names() {
        return { [this.str]: 1 };
      }
    }
    t.Name = r;
    class n extends e {
      constructor(h) {
        super(), this._items = typeof h == "string" ? [h] : h;
      }
      toString() {
        return this.str;
      }
      emptyStr() {
        if (this._items.length > 1)
          return !1;
        const h = this._items[0];
        return h === "" || h === '""';
      }
      get str() {
        var h;
        return (h = this._str) !== null && h !== void 0 ? h : this._str = this._items.reduce((g, $) => `${g}${$}`, "");
      }
      get names() {
        var h;
        return (h = this._names) !== null && h !== void 0 ? h : this._names = this._items.reduce((g, $) => ($ instanceof r && (g[$.str] = (g[$.str] || 0) + 1), g), {});
      }
    }
    t._Code = n, t.nil = new n("");
    function s(m, ...h) {
      const g = [m[0]];
      let $ = 0;
      for (; $ < h.length; )
        o(g, h[$]), g.push(m[++$]);
      return new n(g);
    }
    t._ = s;
    const i = new n("+");
    function a(m, ...h) {
      const g = [w(m[0])];
      let $ = 0;
      for (; $ < h.length; )
        g.push(i), o(g, h[$]), g.push(i, w(m[++$]));
      return c(g), new n(g);
    }
    t.str = a;
    function o(m, h) {
      h instanceof n ? m.push(...h._items) : h instanceof r ? m.push(h) : m.push(p(h));
    }
    t.addCodeArg = o;
    function c(m) {
      let h = 1;
      for (; h < m.length - 1; ) {
        if (m[h] === i) {
          const g = u(m[h - 1], m[h + 1]);
          if (g !== void 0) {
            m.splice(h - 1, 3, g);
            continue;
          }
          m[h++] = "+";
        }
        h++;
      }
    }
    function u(m, h) {
      if (h === '""')
        return m;
      if (m === '""')
        return h;
      if (typeof m == "string")
        return h instanceof r || m[m.length - 1] !== '"' ? void 0 : typeof h != "string" ? `${m.slice(0, -1)}${h}"` : h[0] === '"' ? m.slice(0, -1) + h.slice(1) : void 0;
      if (typeof h == "string" && h[0] === '"' && !(m instanceof r))
        return `"${m}${h.slice(1)}`;
    }
    function l(m, h) {
      return h.emptyStr() ? m : m.emptyStr() ? h : a`${m}${h}`;
    }
    t.strConcat = l;
    function p(m) {
      return typeof m == "number" || typeof m == "boolean" || m === null ? m : w(Array.isArray(m) ? m.join(",") : m);
    }
    function v(m) {
      return new n(w(m));
    }
    t.stringify = v;
    function w(m) {
      return JSON.stringify(m).replace(/\u2028/g, "\\u2028").replace(/\u2029/g, "\\u2029");
    }
    t.safeStringify = w;
    function b(m) {
      return typeof m == "string" && t.IDENTIFIER.test(m) ? new n(`.${m}`) : s`[${m}]`;
    }
    t.getProperty = b;
    function E(m) {
      if (typeof m == "string" && t.IDENTIFIER.test(m))
        return new n(`${m}`);
      throw new Error(`CodeGen: invalid export name: ${m}, use explicit $id name mapping`);
    }
    t.getEsmExportName = E;
    function _(m) {
      return new n(m.toString());
    }
    t.regexpCode = _;
  })(qs)), qs;
}
var Ms = {}, mo;
function go() {
  return mo || (mo = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.ValueScope = t.ValueScopeName = t.Scope = t.varKinds = t.UsedValueState = void 0;
    const e = /* @__PURE__ */ ss();
    class r extends Error {
      constructor(u) {
        super(`CodeGen: "code" for ${u} not defined`), this.value = u.value;
      }
    }
    var n;
    (function(c) {
      c[c.Started = 0] = "Started", c[c.Completed = 1] = "Completed";
    })(n || (t.UsedValueState = n = {})), t.varKinds = {
      const: new e.Name("const"),
      let: new e.Name("let"),
      var: new e.Name("var")
    };
    class s {
      constructor({ prefixes: u, parent: l } = {}) {
        this._names = {}, this._prefixes = u, this._parent = l;
      }
      toName(u) {
        return u instanceof e.Name ? u : this.name(u);
      }
      name(u) {
        return new e.Name(this._newName(u));
      }
      _newName(u) {
        const l = this._names[u] || this._nameGroup(u);
        return `${u}${l.index++}`;
      }
      _nameGroup(u) {
        var l, p;
        if (!((p = (l = this._parent) === null || l === void 0 ? void 0 : l._prefixes) === null || p === void 0) && p.has(u) || this._prefixes && !this._prefixes.has(u))
          throw new Error(`CodeGen: prefix "${u}" is not allowed in this scope`);
        return this._names[u] = { prefix: u, index: 0 };
      }
    }
    t.Scope = s;
    class i extends e.Name {
      constructor(u, l) {
        super(l), this.prefix = u;
      }
      setValue(u, { property: l, itemIndex: p }) {
        this.value = u, this.scopePath = (0, e._)`.${new e.Name(l)}[${p}]`;
      }
    }
    t.ValueScopeName = i;
    const a = (0, e._)`\n`;
    class o extends s {
      constructor(u) {
        super(u), this._values = {}, this._scope = u.scope, this.opts = { ...u, _n: u.lines ? a : e.nil };
      }
      get() {
        return this._scope;
      }
      name(u) {
        return new i(u, this._newName(u));
      }
      value(u, l) {
        var p;
        if (l.ref === void 0)
          throw new Error("CodeGen: ref must be passed in value");
        const v = this.toName(u), { prefix: w } = v, b = (p = l.key) !== null && p !== void 0 ? p : l.ref;
        let E = this._values[w];
        if (E) {
          const h = E.get(b);
          if (h)
            return h;
        } else
          E = this._values[w] = /* @__PURE__ */ new Map();
        E.set(b, v);
        const _ = this._scope[w] || (this._scope[w] = []), m = _.length;
        return _[m] = l.ref, v.setValue(l, { property: w, itemIndex: m }), v;
      }
      getValue(u, l) {
        const p = this._values[u];
        if (p)
          return p.get(l);
      }
      scopeRefs(u, l = this._values) {
        return this._reduceValues(l, (p) => {
          if (p.scopePath === void 0)
            throw new Error(`CodeGen: name "${p}" has no value`);
          return (0, e._)`${u}${p.scopePath}`;
        });
      }
      scopeCode(u = this._values, l, p) {
        return this._reduceValues(u, (v) => {
          if (v.value === void 0)
            throw new Error(`CodeGen: name "${v}" has no value`);
          return v.value.code;
        }, l, p);
      }
      _reduceValues(u, l, p = {}, v) {
        let w = e.nil;
        for (const b in u) {
          const E = u[b];
          if (!E)
            continue;
          const _ = p[b] = p[b] || /* @__PURE__ */ new Map();
          E.forEach((m) => {
            if (_.has(m))
              return;
            _.set(m, n.Started);
            let h = l(m);
            if (h) {
              const g = this.opts.es5 ? t.varKinds.var : t.varKinds.const;
              w = (0, e._)`${w}${g} ${m} = ${h};${this.opts._n}`;
            } else if (h = v == null ? void 0 : v(m))
              w = (0, e._)`${w}${h}${this.opts._n}`;
            else
              throw new r(m);
            _.set(m, n.Completed);
          });
        }
        return w;
      }
    }
    t.ValueScope = o;
  })(Ms)), Ms;
}
var _o;
function oe() {
  return _o || (_o = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.or = t.and = t.not = t.CodeGen = t.operators = t.varKinds = t.ValueScopeName = t.ValueScope = t.Scope = t.Name = t.regexpCode = t.stringify = t.getProperty = t.nil = t.strConcat = t.str = t._ = void 0;
    const e = /* @__PURE__ */ ss(), r = /* @__PURE__ */ go();
    var n = /* @__PURE__ */ ss();
    Object.defineProperty(t, "_", { enumerable: !0, get: function() {
      return n._;
    } }), Object.defineProperty(t, "str", { enumerable: !0, get: function() {
      return n.str;
    } }), Object.defineProperty(t, "strConcat", { enumerable: !0, get: function() {
      return n.strConcat;
    } }), Object.defineProperty(t, "nil", { enumerable: !0, get: function() {
      return n.nil;
    } }), Object.defineProperty(t, "getProperty", { enumerable: !0, get: function() {
      return n.getProperty;
    } }), Object.defineProperty(t, "stringify", { enumerable: !0, get: function() {
      return n.stringify;
    } }), Object.defineProperty(t, "regexpCode", { enumerable: !0, get: function() {
      return n.regexpCode;
    } }), Object.defineProperty(t, "Name", { enumerable: !0, get: function() {
      return n.Name;
    } });
    var s = /* @__PURE__ */ go();
    Object.defineProperty(t, "Scope", { enumerable: !0, get: function() {
      return s.Scope;
    } }), Object.defineProperty(t, "ValueScope", { enumerable: !0, get: function() {
      return s.ValueScope;
    } }), Object.defineProperty(t, "ValueScopeName", { enumerable: !0, get: function() {
      return s.ValueScopeName;
    } }), Object.defineProperty(t, "varKinds", { enumerable: !0, get: function() {
      return s.varKinds;
    } }), t.operators = {
      GT: new e._Code(">"),
      GTE: new e._Code(">="),
      LT: new e._Code("<"),
      LTE: new e._Code("<="),
      EQ: new e._Code("==="),
      NEQ: new e._Code("!=="),
      NOT: new e._Code("!"),
      OR: new e._Code("||"),
      AND: new e._Code("&&"),
      ADD: new e._Code("+")
    };
    class i {
      optimizeNodes() {
        return this;
      }
      optimizeNames(S, T) {
        return this;
      }
    }
    class a extends i {
      constructor(S, T, q) {
        super(), this.varKind = S, this.name = T, this.rhs = q;
      }
      render({ es5: S, _n: T }) {
        const q = S ? r.varKinds.var : this.varKind, J = this.rhs === void 0 ? "" : ` = ${this.rhs}`;
        return `${q} ${this.name}${J};` + T;
      }
      optimizeNames(S, T) {
        if (S[this.name.str])
          return this.rhs && (this.rhs = H(this.rhs, S, T)), this;
      }
      get names() {
        return this.rhs instanceof e._CodeOrName ? this.rhs.names : {};
      }
    }
    class o extends i {
      constructor(S, T, q) {
        super(), this.lhs = S, this.rhs = T, this.sideEffects = q;
      }
      render({ _n: S }) {
        return `${this.lhs} = ${this.rhs};` + S;
      }
      optimizeNames(S, T) {
        if (!(this.lhs instanceof e.Name && !S[this.lhs.str] && !this.sideEffects))
          return this.rhs = H(this.rhs, S, T), this;
      }
      get names() {
        const S = this.lhs instanceof e.Name ? {} : { ...this.lhs.names };
        return F(S, this.rhs);
      }
    }
    class c extends o {
      constructor(S, T, q, J) {
        super(S, q, J), this.op = T;
      }
      render({ _n: S }) {
        return `${this.lhs} ${this.op}= ${this.rhs};` + S;
      }
    }
    class u extends i {
      constructor(S) {
        super(), this.label = S, this.names = {};
      }
      render({ _n: S }) {
        return `${this.label}:` + S;
      }
    }
    class l extends i {
      constructor(S) {
        super(), this.label = S, this.names = {};
      }
      render({ _n: S }) {
        return `break${this.label ? ` ${this.label}` : ""};` + S;
      }
    }
    class p extends i {
      constructor(S) {
        super(), this.error = S;
      }
      render({ _n: S }) {
        return `throw ${this.error};` + S;
      }
      get names() {
        return this.error.names;
      }
    }
    class v extends i {
      constructor(S) {
        super(), this.code = S;
      }
      render({ _n: S }) {
        return `${this.code};` + S;
      }
      optimizeNodes() {
        return `${this.code}` ? this : void 0;
      }
      optimizeNames(S, T) {
        return this.code = H(this.code, S, T), this;
      }
      get names() {
        return this.code instanceof e._CodeOrName ? this.code.names : {};
      }
    }
    class w extends i {
      constructor(S = []) {
        super(), this.nodes = S;
      }
      render(S) {
        return this.nodes.reduce((T, q) => T + q.render(S), "");
      }
      optimizeNodes() {
        const { nodes: S } = this;
        let T = S.length;
        for (; T--; ) {
          const q = S[T].optimizeNodes();
          Array.isArray(q) ? S.splice(T, 1, ...q) : q ? S[T] = q : S.splice(T, 1);
        }
        return S.length > 0 ? this : void 0;
      }
      optimizeNames(S, T) {
        const { nodes: q } = this;
        let J = q.length;
        for (; J--; ) {
          const te = q[J];
          te.optimizeNames(S, T) || (se(S, te.names), q.splice(J, 1));
        }
        return q.length > 0 ? this : void 0;
      }
      get names() {
        return this.nodes.reduce((S, T) => j(S, T.names), {});
      }
    }
    class b extends w {
      render(S) {
        return "{" + S._n + super.render(S) + "}" + S._n;
      }
    }
    class E extends w {
    }
    class _ extends b {
    }
    _.kind = "else";
    class m extends b {
      constructor(S, T) {
        super(T), this.condition = S;
      }
      render(S) {
        let T = `if(${this.condition})` + super.render(S);
        return this.else && (T += "else " + this.else.render(S)), T;
      }
      optimizeNodes() {
        super.optimizeNodes();
        const S = this.condition;
        if (S === !0)
          return this.nodes;
        let T = this.else;
        if (T) {
          const q = T.optimizeNodes();
          T = this.else = Array.isArray(q) ? new _(q) : q;
        }
        if (T)
          return S === !1 ? T instanceof m ? T : T.nodes : this.nodes.length ? this : new m(ke(S), T instanceof m ? [T] : T.nodes);
        if (!(S === !1 || !this.nodes.length))
          return this;
      }
      optimizeNames(S, T) {
        var q;
        if (this.else = (q = this.else) === null || q === void 0 ? void 0 : q.optimizeNames(S, T), !!(super.optimizeNames(S, T) || this.else))
          return this.condition = H(this.condition, S, T), this;
      }
      get names() {
        const S = super.names;
        return F(S, this.condition), this.else && j(S, this.else.names), S;
      }
    }
    m.kind = "if";
    class h extends b {
    }
    h.kind = "for";
    class g extends h {
      constructor(S) {
        super(), this.iteration = S;
      }
      render(S) {
        return `for(${this.iteration})` + super.render(S);
      }
      optimizeNames(S, T) {
        if (super.optimizeNames(S, T))
          return this.iteration = H(this.iteration, S, T), this;
      }
      get names() {
        return j(super.names, this.iteration.names);
      }
    }
    class $ extends h {
      constructor(S, T, q, J) {
        super(), this.varKind = S, this.name = T, this.from = q, this.to = J;
      }
      render(S) {
        const T = S.es5 ? r.varKinds.var : this.varKind, { name: q, from: J, to: te } = this;
        return `for(${T} ${q}=${J}; ${q}<${te}; ${q}++)` + super.render(S);
      }
      get names() {
        const S = F(super.names, this.from);
        return F(S, this.to);
      }
    }
    class d extends h {
      constructor(S, T, q, J) {
        super(), this.loop = S, this.varKind = T, this.name = q, this.iterable = J;
      }
      render(S) {
        return `for(${this.varKind} ${this.name} ${this.loop} ${this.iterable})` + super.render(S);
      }
      optimizeNames(S, T) {
        if (super.optimizeNames(S, T))
          return this.iterable = H(this.iterable, S, T), this;
      }
      get names() {
        return j(super.names, this.iterable.names);
      }
    }
    class f extends b {
      constructor(S, T, q) {
        super(), this.name = S, this.args = T, this.async = q;
      }
      render(S) {
        return `${this.async ? "async " : ""}function ${this.name}(${this.args})` + super.render(S);
      }
    }
    f.kind = "func";
    class y extends w {
      render(S) {
        return "return " + super.render(S);
      }
    }
    y.kind = "return";
    class k extends b {
      render(S) {
        let T = "try" + super.render(S);
        return this.catch && (T += this.catch.render(S)), this.finally && (T += this.finally.render(S)), T;
      }
      optimizeNodes() {
        var S, T;
        return super.optimizeNodes(), (S = this.catch) === null || S === void 0 || S.optimizeNodes(), (T = this.finally) === null || T === void 0 || T.optimizeNodes(), this;
      }
      optimizeNames(S, T) {
        var q, J;
        return super.optimizeNames(S, T), (q = this.catch) === null || q === void 0 || q.optimizeNames(S, T), (J = this.finally) === null || J === void 0 || J.optimizeNames(S, T), this;
      }
      get names() {
        const S = super.names;
        return this.catch && j(S, this.catch.names), this.finally && j(S, this.finally.names), S;
      }
    }
    class I extends b {
      constructor(S) {
        super(), this.error = S;
      }
      render(S) {
        return `catch(${this.error})` + super.render(S);
      }
    }
    I.kind = "catch";
    class N extends b {
      render(S) {
        return "finally" + super.render(S);
      }
    }
    N.kind = "finally";
    class R {
      constructor(S, T = {}) {
        this._values = {}, this._blockStarts = [], this._constants = {}, this.opts = { ...T, _n: T.lines ? `
` : "" }, this._extScope = S, this._scope = new r.Scope({ parent: S }), this._nodes = [new E()];
      }
      toString() {
        return this._root.render(this.opts);
      }
      // returns unique name in the internal scope
      name(S) {
        return this._scope.name(S);
      }
      // reserves unique name in the external scope
      scopeName(S) {
        return this._extScope.name(S);
      }
      // reserves unique name in the external scope and assigns value to it
      scopeValue(S, T) {
        const q = this._extScope.value(S, T);
        return (this._values[q.prefix] || (this._values[q.prefix] = /* @__PURE__ */ new Set())).add(q), q;
      }
      getScopeValue(S, T) {
        return this._extScope.getValue(S, T);
      }
      // return code that assigns values in the external scope to the names that are used internally
      // (same names that were returned by gen.scopeName or gen.scopeValue)
      scopeRefs(S) {
        return this._extScope.scopeRefs(S, this._values);
      }
      scopeCode() {
        return this._extScope.scopeCode(this._values);
      }
      _def(S, T, q, J) {
        const te = this._scope.toName(T);
        return q !== void 0 && J && (this._constants[te.str] = q), this._leafNode(new a(S, te, q)), te;
      }
      // `const` declaration (`var` in es5 mode)
      const(S, T, q) {
        return this._def(r.varKinds.const, S, T, q);
      }
      // `let` declaration with optional assignment (`var` in es5 mode)
      let(S, T, q) {
        return this._def(r.varKinds.let, S, T, q);
      }
      // `var` declaration with optional assignment
      var(S, T, q) {
        return this._def(r.varKinds.var, S, T, q);
      }
      // assignment code
      assign(S, T, q) {
        return this._leafNode(new o(S, T, q));
      }
      // `+=` code
      add(S, T) {
        return this._leafNode(new c(S, t.operators.ADD, T));
      }
      // appends passed SafeExpr to code or executes Block
      code(S) {
        return typeof S == "function" ? S() : S !== e.nil && this._leafNode(new v(S)), this;
      }
      // returns code for object literal for the passed argument list of key-value pairs
      object(...S) {
        const T = ["{"];
        for (const [q, J] of S)
          T.length > 1 && T.push(","), T.push(q), (q !== J || this.opts.es5) && (T.push(":"), (0, e.addCodeArg)(T, J));
        return T.push("}"), new e._Code(T);
      }
      // `if` clause (or statement if `thenBody` and, optionally, `elseBody` are passed)
      if(S, T, q) {
        if (this._blockNode(new m(S)), T && q)
          this.code(T).else().code(q).endIf();
        else if (T)
          this.code(T).endIf();
        else if (q)
          throw new Error('CodeGen: "else" body without "then" body');
        return this;
      }
      // `else if` clause - invalid without `if` or after `else` clauses
      elseIf(S) {
        return this._elseNode(new m(S));
      }
      // `else` clause - only valid after `if` or `else if` clauses
      else() {
        return this._elseNode(new _());
      }
      // end `if` statement (needed if gen.if was used only with condition)
      endIf() {
        return this._endBlockNode(m, _);
      }
      _for(S, T) {
        return this._blockNode(S), T && this.code(T).endFor(), this;
      }
      // a generic `for` clause (or statement if `forBody` is passed)
      for(S, T) {
        return this._for(new g(S), T);
      }
      // `for` statement for a range of values
      forRange(S, T, q, J, te = this.opts.es5 ? r.varKinds.var : r.varKinds.let) {
        const _e = this._scope.toName(S);
        return this._for(new $(te, _e, T, q), () => J(_e));
      }
      // `for-of` statement (in es5 mode replace with a normal for loop)
      forOf(S, T, q, J = r.varKinds.const) {
        const te = this._scope.toName(S);
        if (this.opts.es5) {
          const _e = T instanceof e.Name ? T : this.var("_arr", T);
          return this.forRange("_i", 0, (0, e._)`${_e}.length`, (de) => {
            this.var(te, (0, e._)`${_e}[${de}]`), q(te);
          });
        }
        return this._for(new d("of", J, te, T), () => q(te));
      }
      // `for-in` statement.
      // With option `ownProperties` replaced with a `for-of` loop for object keys
      forIn(S, T, q, J = this.opts.es5 ? r.varKinds.var : r.varKinds.const) {
        if (this.opts.ownProperties)
          return this.forOf(S, (0, e._)`Object.keys(${T})`, q);
        const te = this._scope.toName(S);
        return this._for(new d("in", J, te, T), () => q(te));
      }
      // end `for` loop
      endFor() {
        return this._endBlockNode(h);
      }
      // `label` statement
      label(S) {
        return this._leafNode(new u(S));
      }
      // `break` statement
      break(S) {
        return this._leafNode(new l(S));
      }
      // `return` statement
      return(S) {
        const T = new y();
        if (this._blockNode(T), this.code(S), T.nodes.length !== 1)
          throw new Error('CodeGen: "return" should have one node');
        return this._endBlockNode(y);
      }
      // `try` statement
      try(S, T, q) {
        if (!T && !q)
          throw new Error('CodeGen: "try" without "catch" and "finally"');
        const J = new k();
        if (this._blockNode(J), this.code(S), T) {
          const te = this.name("e");
          this._currNode = J.catch = new I(te), T(te);
        }
        return q && (this._currNode = J.finally = new N(), this.code(q)), this._endBlockNode(I, N);
      }
      // `throw` statement
      throw(S) {
        return this._leafNode(new p(S));
      }
      // start self-balancing block
      block(S, T) {
        return this._blockStarts.push(this._nodes.length), S && this.code(S).endBlock(T), this;
      }
      // end the current self-balancing block
      endBlock(S) {
        const T = this._blockStarts.pop();
        if (T === void 0)
          throw new Error("CodeGen: not in self-balancing block");
        const q = this._nodes.length - T;
        if (q < 0 || S !== void 0 && q !== S)
          throw new Error(`CodeGen: wrong number of nodes: ${q} vs ${S} expected`);
        return this._nodes.length = T, this;
      }
      // `function` heading (or definition if funcBody is passed)
      func(S, T = e.nil, q, J) {
        return this._blockNode(new f(S, T, q)), J && this.code(J).endFunc(), this;
      }
      // end function definition
      endFunc() {
        return this._endBlockNode(f);
      }
      optimize(S = 1) {
        for (; S-- > 0; )
          this._root.optimizeNodes(), this._root.optimizeNames(this._root.names, this._constants);
      }
      _leafNode(S) {
        return this._currNode.nodes.push(S), this;
      }
      _blockNode(S) {
        this._currNode.nodes.push(S), this._nodes.push(S);
      }
      _endBlockNode(S, T) {
        const q = this._currNode;
        if (q instanceof S || T && q instanceof T)
          return this._nodes.pop(), this;
        throw new Error(`CodeGen: not in block "${T ? `${S.kind}/${T.kind}` : S.kind}"`);
      }
      _elseNode(S) {
        const T = this._currNode;
        if (!(T instanceof m))
          throw new Error('CodeGen: "else" without "if"');
        return this._currNode = T.else = S, this;
      }
      get _root() {
        return this._nodes[0];
      }
      get _currNode() {
        const S = this._nodes;
        return S[S.length - 1];
      }
      set _currNode(S) {
        const T = this._nodes;
        T[T.length - 1] = S;
      }
    }
    t.CodeGen = R;
    function j(A, S) {
      for (const T in S)
        A[T] = (A[T] || 0) + (S[T] || 0);
      return A;
    }
    function F(A, S) {
      return S instanceof e._CodeOrName ? j(A, S.names) : A;
    }
    function H(A, S, T) {
      if (A instanceof e.Name)
        return q(A);
      if (!J(A))
        return A;
      return new e._Code(A._items.reduce((te, _e) => (_e instanceof e.Name && (_e = q(_e)), _e instanceof e._Code ? te.push(..._e._items) : te.push(_e), te), []));
      function q(te) {
        const _e = T[te.str];
        return _e === void 0 || S[te.str] !== 1 ? te : (delete S[te.str], _e);
      }
      function J(te) {
        return te instanceof e._Code && te._items.some((_e) => _e instanceof e.Name && S[_e.str] === 1 && T[_e.str] !== void 0);
      }
    }
    function se(A, S) {
      for (const T in S)
        A[T] = (A[T] || 0) - (S[T] || 0);
    }
    function ke(A) {
      return typeof A == "boolean" || typeof A == "number" || A === null ? !A : (0, e._)`!${D(A)}`;
    }
    t.not = ke;
    const je = O(t.operators.AND);
    function fe(...A) {
      return A.reduce(je);
    }
    t.and = fe;
    const Je = O(t.operators.OR);
    function L(...A) {
      return A.reduce(Je);
    }
    t.or = L;
    function O(A) {
      return (S, T) => S === e.nil ? T : T === e.nil ? S : (0, e._)`${D(S)} ${A} ${D(T)}`;
    }
    function D(A) {
      return A instanceof e.Name ? A : (0, e._)`(${A})`;
    }
  })(zs)), zs;
}
var ae = {}, yo;
function pe() {
  if (yo) return ae;
  yo = 1, Object.defineProperty(ae, "__esModule", { value: !0 }), ae.checkStrictMode = ae.getErrorPath = ae.Type = ae.useFunc = ae.setEvaluated = ae.evaluatedPropsToName = ae.mergeEvaluated = ae.eachItem = ae.unescapeJsonPointer = ae.escapeJsonPointer = ae.escapeFragment = ae.unescapeFragment = ae.schemaRefOrVal = ae.schemaHasRulesButRef = ae.schemaHasRules = ae.checkUnknownRules = ae.alwaysValidSchema = ae.toHash = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ ss();
  function r(d) {
    const f = {};
    for (const y of d)
      f[y] = !0;
    return f;
  }
  ae.toHash = r;
  function n(d, f) {
    return typeof f == "boolean" ? f : Object.keys(f).length === 0 ? !0 : (s(d, f), !i(f, d.self.RULES.all));
  }
  ae.alwaysValidSchema = n;
  function s(d, f = d.schema) {
    const { opts: y, self: k } = d;
    if (!y.strictSchema || typeof f == "boolean")
      return;
    const I = k.RULES.keywords;
    for (const N in f)
      I[N] || $(d, `unknown keyword: "${N}"`);
  }
  ae.checkUnknownRules = s;
  function i(d, f) {
    if (typeof d == "boolean")
      return !d;
    for (const y in d)
      if (f[y])
        return !0;
    return !1;
  }
  ae.schemaHasRules = i;
  function a(d, f) {
    if (typeof d == "boolean")
      return !d;
    for (const y in d)
      if (y !== "$ref" && f.all[y])
        return !0;
    return !1;
  }
  ae.schemaHasRulesButRef = a;
  function o({ topSchemaRef: d, schemaPath: f }, y, k, I) {
    if (!I) {
      if (typeof y == "number" || typeof y == "boolean")
        return y;
      if (typeof y == "string")
        return (0, t._)`${y}`;
    }
    return (0, t._)`${d}${f}${(0, t.getProperty)(k)}`;
  }
  ae.schemaRefOrVal = o;
  function c(d) {
    return p(decodeURIComponent(d));
  }
  ae.unescapeFragment = c;
  function u(d) {
    return encodeURIComponent(l(d));
  }
  ae.escapeFragment = u;
  function l(d) {
    return typeof d == "number" ? `${d}` : d.replace(/~/g, "~0").replace(/\//g, "~1");
  }
  ae.escapeJsonPointer = l;
  function p(d) {
    return d.replace(/~1/g, "/").replace(/~0/g, "~");
  }
  ae.unescapeJsonPointer = p;
  function v(d, f) {
    if (Array.isArray(d))
      for (const y of d)
        f(y);
    else
      f(d);
  }
  ae.eachItem = v;
  function w({ mergeNames: d, mergeToName: f, mergeValues: y, resultToName: k }) {
    return (I, N, R, j) => {
      const F = R === void 0 ? N : R instanceof t.Name ? (N instanceof t.Name ? d(I, N, R) : f(I, N, R), R) : N instanceof t.Name ? (f(I, R, N), N) : y(N, R);
      return j === t.Name && !(F instanceof t.Name) ? k(I, F) : F;
    };
  }
  ae.mergeEvaluated = {
    props: w({
      mergeNames: (d, f, y) => d.if((0, t._)`${y} !== true && ${f} !== undefined`, () => {
        d.if((0, t._)`${f} === true`, () => d.assign(y, !0), () => d.assign(y, (0, t._)`${y} || {}`).code((0, t._)`Object.assign(${y}, ${f})`));
      }),
      mergeToName: (d, f, y) => d.if((0, t._)`${y} !== true`, () => {
        f === !0 ? d.assign(y, !0) : (d.assign(y, (0, t._)`${y} || {}`), E(d, y, f));
      }),
      mergeValues: (d, f) => d === !0 ? !0 : { ...d, ...f },
      resultToName: b
    }),
    items: w({
      mergeNames: (d, f, y) => d.if((0, t._)`${y} !== true && ${f} !== undefined`, () => d.assign(y, (0, t._)`${f} === true ? true : ${y} > ${f} ? ${y} : ${f}`)),
      mergeToName: (d, f, y) => d.if((0, t._)`${y} !== true`, () => d.assign(y, f === !0 ? !0 : (0, t._)`${y} > ${f} ? ${y} : ${f}`)),
      mergeValues: (d, f) => d === !0 ? !0 : Math.max(d, f),
      resultToName: (d, f) => d.var("items", f)
    })
  };
  function b(d, f) {
    if (f === !0)
      return d.var("props", !0);
    const y = d.var("props", (0, t._)`{}`);
    return f !== void 0 && E(d, y, f), y;
  }
  ae.evaluatedPropsToName = b;
  function E(d, f, y) {
    Object.keys(y).forEach((k) => d.assign((0, t._)`${f}${(0, t.getProperty)(k)}`, !0));
  }
  ae.setEvaluated = E;
  const _ = {};
  function m(d, f) {
    return d.scopeValue("func", {
      ref: f,
      code: _[f.code] || (_[f.code] = new e._Code(f.code))
    });
  }
  ae.useFunc = m;
  var h;
  (function(d) {
    d[d.Num = 0] = "Num", d[d.Str = 1] = "Str";
  })(h || (ae.Type = h = {}));
  function g(d, f, y) {
    if (d instanceof t.Name) {
      const k = f === h.Num;
      return y ? k ? (0, t._)`"[" + ${d} + "]"` : (0, t._)`"['" + ${d} + "']"` : k ? (0, t._)`"/" + ${d}` : (0, t._)`"/" + ${d}.replace(/~/g, "~0").replace(/\\//g, "~1")`;
    }
    return y ? (0, t.getProperty)(d).toString() : "/" + l(d);
  }
  ae.getErrorPath = g;
  function $(d, f, y = d.opts.strictSchema) {
    if (y) {
      if (f = `strict mode: ${f}`, y === !0)
        throw new Error(f);
      d.self.logger.warn(f);
    }
  }
  return ae.checkStrictMode = $, ae;
}
var Qr = {}, vo;
function Dt() {
  if (vo) return Qr;
  vo = 1, Object.defineProperty(Qr, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = {
    // validation function arguments
    data: new t.Name("data"),
    // data passed to validation function
    // args passed from referencing schema
    valCxt: new t.Name("valCxt"),
    // validation/data context - should not be used directly, it is destructured to the names below
    instancePath: new t.Name("instancePath"),
    parentData: new t.Name("parentData"),
    parentDataProperty: new t.Name("parentDataProperty"),
    rootData: new t.Name("rootData"),
    // root data - same as the data passed to the first/top validation function
    dynamicAnchors: new t.Name("dynamicAnchors"),
    // used to support recursiveRef and dynamicRef
    // function scoped variables
    vErrors: new t.Name("vErrors"),
    // null or array of validation errors
    errors: new t.Name("errors"),
    // counter of validation errors
    this: new t.Name("this"),
    // "globals"
    self: new t.Name("self"),
    scope: new t.Name("scope"),
    // JTD serialize/parse name for JSON string and position
    json: new t.Name("json"),
    jsonPos: new t.Name("jsonPos"),
    jsonLen: new t.Name("jsonLen"),
    jsonPart: new t.Name("jsonPart")
  };
  return Qr.default = e, Qr;
}
var wo;
function fs() {
  return wo || (wo = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.extendErrors = t.resetErrorsCount = t.reportExtraError = t.reportError = t.keyword$DataError = t.keywordError = void 0;
    const e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ pe(), n = /* @__PURE__ */ Dt();
    t.keywordError = {
      message: ({ keyword: _ }) => (0, e.str)`must pass "${_}" keyword validation`
    }, t.keyword$DataError = {
      message: ({ keyword: _, schemaType: m }) => m ? (0, e.str)`"${_}" keyword must be ${m} ($data)` : (0, e.str)`"${_}" keyword is invalid ($data)`
    };
    function s(_, m = t.keywordError, h, g) {
      const { it: $ } = _, { gen: d, compositeRule: f, allErrors: y } = $, k = p(_, m, h);
      g ?? (f || y) ? c(d, k) : u($, (0, e._)`[${k}]`);
    }
    t.reportError = s;
    function i(_, m = t.keywordError, h) {
      const { it: g } = _, { gen: $, compositeRule: d, allErrors: f } = g, y = p(_, m, h);
      c($, y), d || f || u(g, n.default.vErrors);
    }
    t.reportExtraError = i;
    function a(_, m) {
      _.assign(n.default.errors, m), _.if((0, e._)`${n.default.vErrors} !== null`, () => _.if(m, () => _.assign((0, e._)`${n.default.vErrors}.length`, m), () => _.assign(n.default.vErrors, null)));
    }
    t.resetErrorsCount = a;
    function o({ gen: _, keyword: m, schemaValue: h, data: g, errsCount: $, it: d }) {
      if ($ === void 0)
        throw new Error("ajv implementation error");
      const f = _.name("err");
      _.forRange("i", $, n.default.errors, (y) => {
        _.const(f, (0, e._)`${n.default.vErrors}[${y}]`), _.if((0, e._)`${f}.instancePath === undefined`, () => _.assign((0, e._)`${f}.instancePath`, (0, e.strConcat)(n.default.instancePath, d.errorPath))), _.assign((0, e._)`${f}.schemaPath`, (0, e.str)`${d.errSchemaPath}/${m}`), d.opts.verbose && (_.assign((0, e._)`${f}.schema`, h), _.assign((0, e._)`${f}.data`, g));
      });
    }
    t.extendErrors = o;
    function c(_, m) {
      const h = _.const("err", m);
      _.if((0, e._)`${n.default.vErrors} === null`, () => _.assign(n.default.vErrors, (0, e._)`[${h}]`), (0, e._)`${n.default.vErrors}.push(${h})`), _.code((0, e._)`${n.default.errors}++`);
    }
    function u(_, m) {
      const { gen: h, validateName: g, schemaEnv: $ } = _;
      $.$async ? h.throw((0, e._)`new ${_.ValidationError}(${m})`) : (h.assign((0, e._)`${g}.errors`, m), h.return(!1));
    }
    const l = {
      keyword: new e.Name("keyword"),
      schemaPath: new e.Name("schemaPath"),
      // also used in JTD errors
      params: new e.Name("params"),
      propertyName: new e.Name("propertyName"),
      message: new e.Name("message"),
      schema: new e.Name("schema"),
      parentSchema: new e.Name("parentSchema")
    };
    function p(_, m, h) {
      const { createErrors: g } = _.it;
      return g === !1 ? (0, e._)`{}` : v(_, m, h);
    }
    function v(_, m, h = {}) {
      const { gen: g, it: $ } = _, d = [
        w($, h),
        b(_, h)
      ];
      return E(_, m, d), g.object(...d);
    }
    function w({ errorPath: _ }, { instancePath: m }) {
      const h = m ? (0, e.str)`${_}${(0, r.getErrorPath)(m, r.Type.Str)}` : _;
      return [n.default.instancePath, (0, e.strConcat)(n.default.instancePath, h)];
    }
    function b({ keyword: _, it: { errSchemaPath: m } }, { schemaPath: h, parentSchema: g }) {
      let $ = g ? m : (0, e.str)`${m}/${_}`;
      return h && ($ = (0, e.str)`${$}${(0, r.getErrorPath)(h, r.Type.Str)}`), [l.schemaPath, $];
    }
    function E(_, { params: m, message: h }, g) {
      const { keyword: $, data: d, schemaValue: f, it: y } = _, { opts: k, propertyName: I, topSchemaRef: N, schemaPath: R } = y;
      g.push([l.keyword, $], [l.params, typeof m == "function" ? m(_) : m || (0, e._)`{}`]), k.messages && g.push([l.message, typeof h == "function" ? h(_) : h]), k.verbose && g.push([l.schema, f], [l.parentSchema, (0, e._)`${N}${R}`], [n.default.data, d]), I && g.push([l.propertyName, I]);
    }
  })(js)), js;
}
var bo;
function Sy() {
  if (bo) return Zt;
  bo = 1, Object.defineProperty(Zt, "__esModule", { value: !0 }), Zt.boolOrEmptySchema = Zt.topBoolOrEmptySchema = void 0;
  const t = /* @__PURE__ */ fs(), e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ Dt(), n = {
    message: "boolean schema is false"
  };
  function s(o) {
    const { gen: c, schema: u, validateName: l } = o;
    u === !1 ? a(o, !1) : typeof u == "object" && u.$async === !0 ? c.return(r.default.data) : (c.assign((0, e._)`${l}.errors`, null), c.return(!0));
  }
  Zt.topBoolOrEmptySchema = s;
  function i(o, c) {
    const { gen: u, schema: l } = o;
    l === !1 ? (u.var(c, !1), a(o)) : u.var(c, !0);
  }
  Zt.boolOrEmptySchema = i;
  function a(o, c) {
    const { gen: u, data: l } = o, p = {
      gen: u,
      keyword: "false schema",
      data: l,
      schema: !1,
      schemaCode: !1,
      schemaValue: !1,
      params: {},
      it: o
    };
    (0, t.reportError)(p, n, void 0, c);
  }
  return Zt;
}
var Ze = {}, Ft = {}, So;
function ll() {
  if (So) return Ft;
  So = 1, Object.defineProperty(Ft, "__esModule", { value: !0 }), Ft.getRules = Ft.isJSONType = void 0;
  const t = ["string", "number", "integer", "boolean", "null", "object", "array"], e = new Set(t);
  function r(s) {
    return typeof s == "string" && e.has(s);
  }
  Ft.isJSONType = r;
  function n() {
    const s = {
      number: { type: "number", rules: [] },
      string: { type: "string", rules: [] },
      array: { type: "array", rules: [] },
      object: { type: "object", rules: [] }
    };
    return {
      types: { ...s, integer: !0, boolean: !0, null: !0 },
      rules: [{ rules: [] }, s.number, s.string, s.array, s.object],
      post: { rules: [] },
      all: {},
      keywords: {}
    };
  }
  return Ft.getRules = n, Ft;
}
var Et = {}, ko;
function dl() {
  if (ko) return Et;
  ko = 1, Object.defineProperty(Et, "__esModule", { value: !0 }), Et.shouldUseRule = Et.shouldUseGroup = Et.schemaHasRulesForType = void 0;
  function t({ schema: n, self: s }, i) {
    const a = s.RULES.types[i];
    return a && a !== !0 && e(n, a);
  }
  Et.schemaHasRulesForType = t;
  function e(n, s) {
    return s.rules.some((i) => r(n, i));
  }
  Et.shouldUseGroup = e;
  function r(n, s) {
    var i;
    return n[s.keyword] !== void 0 || ((i = s.definition.implements) === null || i === void 0 ? void 0 : i.some((a) => n[a] !== void 0));
  }
  return Et.shouldUseRule = r, Et;
}
var $o;
function is() {
  if ($o) return Ze;
  $o = 1, Object.defineProperty(Ze, "__esModule", { value: !0 }), Ze.reportTypeError = Ze.checkDataTypes = Ze.checkDataType = Ze.coerceAndCheckDataType = Ze.getJSONTypes = Ze.getSchemaTypes = Ze.DataType = void 0;
  const t = /* @__PURE__ */ ll(), e = /* @__PURE__ */ dl(), r = /* @__PURE__ */ fs(), n = /* @__PURE__ */ oe(), s = /* @__PURE__ */ pe();
  var i;
  (function(h) {
    h[h.Correct = 0] = "Correct", h[h.Wrong = 1] = "Wrong";
  })(i || (Ze.DataType = i = {}));
  function a(h) {
    const g = o(h.type);
    if (g.includes("null")) {
      if (h.nullable === !1)
        throw new Error("type: null contradicts nullable: false");
    } else {
      if (!g.length && h.nullable !== void 0)
        throw new Error('"nullable" cannot be used without "type"');
      h.nullable === !0 && g.push("null");
    }
    return g;
  }
  Ze.getSchemaTypes = a;
  function o(h) {
    const g = Array.isArray(h) ? h : h ? [h] : [];
    if (g.every(t.isJSONType))
      return g;
    throw new Error("type must be JSONType or JSONType[]: " + g.join(","));
  }
  Ze.getJSONTypes = o;
  function c(h, g) {
    const { gen: $, data: d, opts: f } = h, y = l(g, f.coerceTypes), k = g.length > 0 && !(y.length === 0 && g.length === 1 && (0, e.schemaHasRulesForType)(h, g[0]));
    if (k) {
      const I = b(g, d, f.strictNumbers, i.Wrong);
      $.if(I, () => {
        y.length ? p(h, g, y) : _(h);
      });
    }
    return k;
  }
  Ze.coerceAndCheckDataType = c;
  const u = /* @__PURE__ */ new Set(["string", "number", "integer", "boolean", "null"]);
  function l(h, g) {
    return g ? h.filter(($) => u.has($) || g === "array" && $ === "array") : [];
  }
  function p(h, g, $) {
    const { gen: d, data: f, opts: y } = h, k = d.let("dataType", (0, n._)`typeof ${f}`), I = d.let("coerced", (0, n._)`undefined`);
    y.coerceTypes === "array" && d.if((0, n._)`${k} == 'object' && Array.isArray(${f}) && ${f}.length == 1`, () => d.assign(f, (0, n._)`${f}[0]`).assign(k, (0, n._)`typeof ${f}`).if(b(g, f, y.strictNumbers), () => d.assign(I, f))), d.if((0, n._)`${I} !== undefined`);
    for (const R of $)
      (u.has(R) || R === "array" && y.coerceTypes === "array") && N(R);
    d.else(), _(h), d.endIf(), d.if((0, n._)`${I} !== undefined`, () => {
      d.assign(f, I), v(h, I);
    });
    function N(R) {
      switch (R) {
        case "string":
          d.elseIf((0, n._)`${k} == "number" || ${k} == "boolean"`).assign(I, (0, n._)`"" + ${f}`).elseIf((0, n._)`${f} === null`).assign(I, (0, n._)`""`);
          return;
        case "number":
          d.elseIf((0, n._)`${k} == "boolean" || ${f} === null
              || (${k} == "string" && ${f} && ${f} == +${f})`).assign(I, (0, n._)`+${f}`);
          return;
        case "integer":
          d.elseIf((0, n._)`${k} === "boolean" || ${f} === null
              || (${k} === "string" && ${f} && ${f} == +${f} && !(${f} % 1))`).assign(I, (0, n._)`+${f}`);
          return;
        case "boolean":
          d.elseIf((0, n._)`${f} === "false" || ${f} === 0 || ${f} === null`).assign(I, !1).elseIf((0, n._)`${f} === "true" || ${f} === 1`).assign(I, !0);
          return;
        case "null":
          d.elseIf((0, n._)`${f} === "" || ${f} === 0 || ${f} === false`), d.assign(I, null);
          return;
        case "array":
          d.elseIf((0, n._)`${k} === "string" || ${k} === "number"
              || ${k} === "boolean" || ${f} === null`).assign(I, (0, n._)`[${f}]`);
      }
    }
  }
  function v({ gen: h, parentData: g, parentDataProperty: $ }, d) {
    h.if((0, n._)`${g} !== undefined`, () => h.assign((0, n._)`${g}[${$}]`, d));
  }
  function w(h, g, $, d = i.Correct) {
    const f = d === i.Correct ? n.operators.EQ : n.operators.NEQ;
    let y;
    switch (h) {
      case "null":
        return (0, n._)`${g} ${f} null`;
      case "array":
        y = (0, n._)`Array.isArray(${g})`;
        break;
      case "object":
        y = (0, n._)`${g} && typeof ${g} == "object" && !Array.isArray(${g})`;
        break;
      case "integer":
        y = k((0, n._)`!(${g} % 1) && !isNaN(${g})`);
        break;
      case "number":
        y = k();
        break;
      default:
        return (0, n._)`typeof ${g} ${f} ${h}`;
    }
    return d === i.Correct ? y : (0, n.not)(y);
    function k(I = n.nil) {
      return (0, n.and)((0, n._)`typeof ${g} == "number"`, I, $ ? (0, n._)`isFinite(${g})` : n.nil);
    }
  }
  Ze.checkDataType = w;
  function b(h, g, $, d) {
    if (h.length === 1)
      return w(h[0], g, $, d);
    let f;
    const y = (0, s.toHash)(h);
    if (y.array && y.object) {
      const k = (0, n._)`typeof ${g} != "object"`;
      f = y.null ? k : (0, n._)`!${g} || ${k}`, delete y.null, delete y.array, delete y.object;
    } else
      f = n.nil;
    y.number && delete y.integer;
    for (const k in y)
      f = (0, n.and)(f, w(k, g, $, d));
    return f;
  }
  Ze.checkDataTypes = b;
  const E = {
    message: ({ schema: h }) => `must be ${h}`,
    params: ({ schema: h, schemaValue: g }) => typeof h == "string" ? (0, n._)`{type: ${h}}` : (0, n._)`{type: ${g}}`
  };
  function _(h) {
    const g = m(h);
    (0, r.reportError)(g, E);
  }
  Ze.reportTypeError = _;
  function m(h) {
    const { gen: g, data: $, schema: d } = h, f = (0, s.schemaRefOrVal)(h, d, "type");
    return {
      gen: g,
      keyword: "type",
      data: $,
      schema: d.type,
      schemaCode: f,
      schemaValue: f,
      parentSchema: d,
      params: {},
      it: h
    };
  }
  return Ze;
}
var yr = {}, Eo;
function ky() {
  if (Eo) return yr;
  Eo = 1, Object.defineProperty(yr, "__esModule", { value: !0 }), yr.assignDefaults = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe();
  function r(s, i) {
    const { properties: a, items: o } = s.schema;
    if (i === "object" && a)
      for (const c in a)
        n(s, c, a[c].default);
    else i === "array" && Array.isArray(o) && o.forEach((c, u) => n(s, u, c.default));
  }
  yr.assignDefaults = r;
  function n(s, i, a) {
    const { gen: o, compositeRule: c, data: u, opts: l } = s;
    if (a === void 0)
      return;
    const p = (0, t._)`${u}${(0, t.getProperty)(i)}`;
    if (c) {
      (0, e.checkStrictMode)(s, `default is ignored for: ${p}`);
      return;
    }
    let v = (0, t._)`${p} === undefined`;
    l.useDefaults === "empty" && (v = (0, t._)`${v} || ${p} === null || ${p} === ""`), o.if(v, (0, t._)`${p} = ${(0, t.stringify)(a)}`);
  }
  return yr;
}
var ht = {}, we = {}, To;
function pt() {
  if (To) return we;
  To = 1, Object.defineProperty(we, "__esModule", { value: !0 }), we.validateUnion = we.validateArray = we.usePattern = we.callValidateCode = we.schemaProperties = we.allSchemaProperties = we.noPropertyInData = we.propertyInData = we.isOwnProperty = we.hasPropFunc = we.reportMissingProp = we.checkMissingProp = we.checkReportMissingProp = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ Dt(), n = /* @__PURE__ */ pe();
  function s(h, g) {
    const { gen: $, data: d, it: f } = h;
    $.if(l($, d, g, f.opts.ownProperties), () => {
      h.setParams({ missingProperty: (0, t._)`${g}` }, !0), h.error();
    });
  }
  we.checkReportMissingProp = s;
  function i({ gen: h, data: g, it: { opts: $ } }, d, f) {
    return (0, t.or)(...d.map((y) => (0, t.and)(l(h, g, y, $.ownProperties), (0, t._)`${f} = ${y}`)));
  }
  we.checkMissingProp = i;
  function a(h, g) {
    h.setParams({ missingProperty: g }, !0), h.error();
  }
  we.reportMissingProp = a;
  function o(h) {
    return h.scopeValue("func", {
      // eslint-disable-next-line @typescript-eslint/unbound-method
      ref: Object.prototype.hasOwnProperty,
      code: (0, t._)`Object.prototype.hasOwnProperty`
    });
  }
  we.hasPropFunc = o;
  function c(h, g, $) {
    return (0, t._)`${o(h)}.call(${g}, ${$})`;
  }
  we.isOwnProperty = c;
  function u(h, g, $, d) {
    const f = (0, t._)`${g}${(0, t.getProperty)($)} !== undefined`;
    return d ? (0, t._)`${f} && ${c(h, g, $)}` : f;
  }
  we.propertyInData = u;
  function l(h, g, $, d) {
    const f = (0, t._)`${g}${(0, t.getProperty)($)} === undefined`;
    return d ? (0, t.or)(f, (0, t.not)(c(h, g, $))) : f;
  }
  we.noPropertyInData = l;
  function p(h) {
    return h ? Object.keys(h).filter((g) => g !== "__proto__") : [];
  }
  we.allSchemaProperties = p;
  function v(h, g) {
    return p(g).filter(($) => !(0, e.alwaysValidSchema)(h, g[$]));
  }
  we.schemaProperties = v;
  function w({ schemaCode: h, data: g, it: { gen: $, topSchemaRef: d, schemaPath: f, errorPath: y }, it: k }, I, N, R) {
    const j = R ? (0, t._)`${h}, ${g}, ${d}${f}` : g, F = [
      [r.default.instancePath, (0, t.strConcat)(r.default.instancePath, y)],
      [r.default.parentData, k.parentData],
      [r.default.parentDataProperty, k.parentDataProperty],
      [r.default.rootData, r.default.rootData]
    ];
    k.opts.dynamicRef && F.push([r.default.dynamicAnchors, r.default.dynamicAnchors]);
    const H = (0, t._)`${j}, ${$.object(...F)}`;
    return N !== t.nil ? (0, t._)`${I}.call(${N}, ${H})` : (0, t._)`${I}(${H})`;
  }
  we.callValidateCode = w;
  const b = (0, t._)`new RegExp`;
  function E({ gen: h, it: { opts: g } }, $) {
    const d = g.unicodeRegExp ? "u" : "", { regExp: f } = g.code, y = f($, d);
    return h.scopeValue("pattern", {
      key: y.toString(),
      ref: y,
      code: (0, t._)`${f.code === "new RegExp" ? b : (0, n.useFunc)(h, f)}(${$}, ${d})`
    });
  }
  we.usePattern = E;
  function _(h) {
    const { gen: g, data: $, keyword: d, it: f } = h, y = g.name("valid");
    if (f.allErrors) {
      const I = g.let("valid", !0);
      return k(() => g.assign(I, !1)), I;
    }
    return g.var(y, !0), k(() => g.break()), y;
    function k(I) {
      const N = g.const("len", (0, t._)`${$}.length`);
      g.forRange("i", 0, N, (R) => {
        h.subschema({
          keyword: d,
          dataProp: R,
          dataPropType: e.Type.Num
        }, y), g.if((0, t.not)(y), I);
      });
    }
  }
  we.validateArray = _;
  function m(h) {
    const { gen: g, schema: $, keyword: d, it: f } = h;
    if (!Array.isArray($))
      throw new Error("ajv implementation error");
    if ($.some((N) => (0, e.alwaysValidSchema)(f, N)) && !f.opts.unevaluated)
      return;
    const k = g.let("valid", !1), I = g.name("_valid");
    g.block(() => $.forEach((N, R) => {
      const j = h.subschema({
        keyword: d,
        schemaProp: R,
        compositeRule: !0
      }, I);
      g.assign(k, (0, t._)`${k} || ${I}`), h.mergeValidEvaluated(j, I) || g.if((0, t.not)(k));
    })), h.result(k, () => h.reset(), () => h.error(!0));
  }
  return we.validateUnion = m, we;
}
var Ro;
function $y() {
  if (Ro) return ht;
  Ro = 1, Object.defineProperty(ht, "__esModule", { value: !0 }), ht.validateKeywordUsage = ht.validSchemaType = ht.funcKeywordCode = ht.macroKeywordCode = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ Dt(), r = /* @__PURE__ */ pt(), n = /* @__PURE__ */ fs();
  function s(v, w) {
    const { gen: b, keyword: E, schema: _, parentSchema: m, it: h } = v, g = w.macro.call(h.self, _, m, h), $ = u(b, E, g);
    h.opts.validateSchema !== !1 && h.self.validateSchema(g, !0);
    const d = b.name("valid");
    v.subschema({
      schema: g,
      schemaPath: t.nil,
      errSchemaPath: `${h.errSchemaPath}/${E}`,
      topSchemaRef: $,
      compositeRule: !0
    }, d), v.pass(d, () => v.error(!0));
  }
  ht.macroKeywordCode = s;
  function i(v, w) {
    var b;
    const { gen: E, keyword: _, schema: m, parentSchema: h, $data: g, it: $ } = v;
    c($, w);
    const d = !g && w.compile ? w.compile.call($.self, m, h, $) : w.validate, f = u(E, _, d), y = E.let("valid");
    v.block$data(y, k), v.ok((b = w.valid) !== null && b !== void 0 ? b : y);
    function k() {
      if (w.errors === !1)
        R(), w.modifying && a(v), j(() => v.error());
      else {
        const F = w.async ? I() : N();
        w.modifying && a(v), j(() => o(v, F));
      }
    }
    function I() {
      const F = E.let("ruleErrs", null);
      return E.try(() => R((0, t._)`await `), (H) => E.assign(y, !1).if((0, t._)`${H} instanceof ${$.ValidationError}`, () => E.assign(F, (0, t._)`${H}.errors`), () => E.throw(H))), F;
    }
    function N() {
      const F = (0, t._)`${f}.errors`;
      return E.assign(F, null), R(t.nil), F;
    }
    function R(F = w.async ? (0, t._)`await ` : t.nil) {
      const H = $.opts.passContext ? e.default.this : e.default.self, se = !("compile" in w && !g || w.schema === !1);
      E.assign(y, (0, t._)`${F}${(0, r.callValidateCode)(v, f, H, se)}`, w.modifying);
    }
    function j(F) {
      var H;
      E.if((0, t.not)((H = w.valid) !== null && H !== void 0 ? H : y), F);
    }
  }
  ht.funcKeywordCode = i;
  function a(v) {
    const { gen: w, data: b, it: E } = v;
    w.if(E.parentData, () => w.assign(b, (0, t._)`${E.parentData}[${E.parentDataProperty}]`));
  }
  function o(v, w) {
    const { gen: b } = v;
    b.if((0, t._)`Array.isArray(${w})`, () => {
      b.assign(e.default.vErrors, (0, t._)`${e.default.vErrors} === null ? ${w} : ${e.default.vErrors}.concat(${w})`).assign(e.default.errors, (0, t._)`${e.default.vErrors}.length`), (0, n.extendErrors)(v);
    }, () => v.error());
  }
  function c({ schemaEnv: v }, w) {
    if (w.async && !v.$async)
      throw new Error("async keyword in sync schema");
  }
  function u(v, w, b) {
    if (b === void 0)
      throw new Error(`keyword "${w}" failed to compile`);
    return v.scopeValue("keyword", typeof b == "function" ? { ref: b } : { ref: b, code: (0, t.stringify)(b) });
  }
  function l(v, w, b = !1) {
    return !w.length || w.some((E) => E === "array" ? Array.isArray(v) : E === "object" ? v && typeof v == "object" && !Array.isArray(v) : typeof v == E || b && typeof v > "u");
  }
  ht.validSchemaType = l;
  function p({ schema: v, opts: w, self: b, errSchemaPath: E }, _, m) {
    if (Array.isArray(_.keyword) ? !_.keyword.includes(m) : _.keyword !== m)
      throw new Error("ajv implementation error");
    const h = _.dependencies;
    if (h != null && h.some((g) => !Object.prototype.hasOwnProperty.call(v, g)))
      throw new Error(`parent schema must have dependencies of ${m}: ${h.join(",")}`);
    if (_.validateSchema && !_.validateSchema(v[m])) {
      const $ = `keyword "${m}" value is invalid at path "${E}": ` + b.errorsText(_.validateSchema.errors);
      if (w.validateSchema === "log")
        b.logger.error($);
      else
        throw new Error($);
    }
  }
  return ht.validateKeywordUsage = p, ht;
}
var Tt = {}, Io;
function Ey() {
  if (Io) return Tt;
  Io = 1, Object.defineProperty(Tt, "__esModule", { value: !0 }), Tt.extendSubschemaMode = Tt.extendSubschemaData = Tt.getSubschema = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe();
  function r(i, { keyword: a, schemaProp: o, schema: c, schemaPath: u, errSchemaPath: l, topSchemaRef: p }) {
    if (a !== void 0 && c !== void 0)
      throw new Error('both "keyword" and "schema" passed, only one allowed');
    if (a !== void 0) {
      const v = i.schema[a];
      return o === void 0 ? {
        schema: v,
        schemaPath: (0, t._)`${i.schemaPath}${(0, t.getProperty)(a)}`,
        errSchemaPath: `${i.errSchemaPath}/${a}`
      } : {
        schema: v[o],
        schemaPath: (0, t._)`${i.schemaPath}${(0, t.getProperty)(a)}${(0, t.getProperty)(o)}`,
        errSchemaPath: `${i.errSchemaPath}/${a}/${(0, e.escapeFragment)(o)}`
      };
    }
    if (c !== void 0) {
      if (u === void 0 || l === void 0 || p === void 0)
        throw new Error('"schemaPath", "errSchemaPath" and "topSchemaRef" are required with "schema"');
      return {
        schema: c,
        schemaPath: u,
        topSchemaRef: p,
        errSchemaPath: l
      };
    }
    throw new Error('either "keyword" or "schema" must be passed');
  }
  Tt.getSubschema = r;
  function n(i, a, { dataProp: o, dataPropType: c, data: u, dataTypes: l, propertyName: p }) {
    if (u !== void 0 && o !== void 0)
      throw new Error('both "data" and "dataProp" passed, only one allowed');
    const { gen: v } = a;
    if (o !== void 0) {
      const { errorPath: b, dataPathArr: E, opts: _ } = a, m = v.let("data", (0, t._)`${a.data}${(0, t.getProperty)(o)}`, !0);
      w(m), i.errorPath = (0, t.str)`${b}${(0, e.getErrorPath)(o, c, _.jsPropertySyntax)}`, i.parentDataProperty = (0, t._)`${o}`, i.dataPathArr = [...E, i.parentDataProperty];
    }
    if (u !== void 0) {
      const b = u instanceof t.Name ? u : v.let("data", u, !0);
      w(b), p !== void 0 && (i.propertyName = p);
    }
    l && (i.dataTypes = l);
    function w(b) {
      i.data = b, i.dataLevel = a.dataLevel + 1, i.dataTypes = [], a.definedProperties = /* @__PURE__ */ new Set(), i.parentData = a.data, i.dataNames = [...a.dataNames, b];
    }
  }
  Tt.extendSubschemaData = n;
  function s(i, { jtdDiscriminator: a, jtdMetadata: o, compositeRule: c, createErrors: u, allErrors: l }) {
    c !== void 0 && (i.compositeRule = c), u !== void 0 && (i.createErrors = u), l !== void 0 && (i.allErrors = l), i.jtdDiscriminator = a, i.jtdMetadata = o;
  }
  return Tt.extendSubschemaMode = s, Tt;
}
var Ge = {}, Ds, Po;
function hl() {
  return Po || (Po = 1, Ds = function t(e, r) {
    if (e === r) return !0;
    if (e && r && typeof e == "object" && typeof r == "object") {
      if (e.constructor !== r.constructor) return !1;
      var n, s, i;
      if (Array.isArray(e)) {
        if (n = e.length, n != r.length) return !1;
        for (s = n; s-- !== 0; )
          if (!t(e[s], r[s])) return !1;
        return !0;
      }
      if (e.constructor === RegExp) return e.source === r.source && e.flags === r.flags;
      if (e.valueOf !== Object.prototype.valueOf) return e.valueOf() === r.valueOf();
      if (e.toString !== Object.prototype.toString) return e.toString() === r.toString();
      if (i = Object.keys(e), n = i.length, n !== Object.keys(r).length) return !1;
      for (s = n; s-- !== 0; )
        if (!Object.prototype.hasOwnProperty.call(r, i[s])) return !1;
      for (s = n; s-- !== 0; ) {
        var a = i[s];
        if (!t(e[a], r[a])) return !1;
      }
      return !0;
    }
    return e !== e && r !== r;
  }), Ds;
}
var Us = { exports: {} }, Oo;
function Ty() {
  if (Oo) return Us.exports;
  Oo = 1;
  var t = Us.exports = function(n, s, i) {
    typeof s == "function" && (i = s, s = {}), i = s.cb || i;
    var a = typeof i == "function" ? i : i.pre || function() {
    }, o = i.post || function() {
    };
    e(s, a, o, n, "", n);
  };
  t.keywords = {
    additionalItems: !0,
    items: !0,
    contains: !0,
    additionalProperties: !0,
    propertyNames: !0,
    not: !0,
    if: !0,
    then: !0,
    else: !0
  }, t.arrayKeywords = {
    items: !0,
    allOf: !0,
    anyOf: !0,
    oneOf: !0
  }, t.propsKeywords = {
    $defs: !0,
    definitions: !0,
    properties: !0,
    patternProperties: !0,
    dependencies: !0
  }, t.skipKeywords = {
    default: !0,
    enum: !0,
    const: !0,
    required: !0,
    maximum: !0,
    minimum: !0,
    exclusiveMaximum: !0,
    exclusiveMinimum: !0,
    multipleOf: !0,
    maxLength: !0,
    minLength: !0,
    pattern: !0,
    format: !0,
    maxItems: !0,
    minItems: !0,
    uniqueItems: !0,
    maxProperties: !0,
    minProperties: !0
  };
  function e(n, s, i, a, o, c, u, l, p, v) {
    if (a && typeof a == "object" && !Array.isArray(a)) {
      s(a, o, c, u, l, p, v);
      for (var w in a) {
        var b = a[w];
        if (Array.isArray(b)) {
          if (w in t.arrayKeywords)
            for (var E = 0; E < b.length; E++)
              e(n, s, i, b[E], o + "/" + w + "/" + E, c, o, w, a, E);
        } else if (w in t.propsKeywords) {
          if (b && typeof b == "object")
            for (var _ in b)
              e(n, s, i, b[_], o + "/" + w + "/" + r(_), c, o, w, a, _);
        } else (w in t.keywords || n.allKeys && !(w in t.skipKeywords)) && e(n, s, i, b, o + "/" + w, c, o, w, a);
      }
      i(a, o, c, u, l, p, v);
    }
  }
  function r(n) {
    return n.replace(/~/g, "~0").replace(/\//g, "~1");
  }
  return Us.exports;
}
var Co;
function ps() {
  if (Co) return Ge;
  Co = 1, Object.defineProperty(Ge, "__esModule", { value: !0 }), Ge.getSchemaRefs = Ge.resolveUrl = Ge.normalizeId = Ge._getFullPath = Ge.getFullPath = Ge.inlineRef = void 0;
  const t = /* @__PURE__ */ pe(), e = hl(), r = Ty(), n = /* @__PURE__ */ new Set([
    "type",
    "format",
    "pattern",
    "maxLength",
    "minLength",
    "maxProperties",
    "minProperties",
    "maxItems",
    "minItems",
    "maximum",
    "minimum",
    "uniqueItems",
    "multipleOf",
    "required",
    "enum",
    "const"
  ]);
  function s(E, _ = !0) {
    return typeof E == "boolean" ? !0 : _ === !0 ? !a(E) : _ ? o(E) <= _ : !1;
  }
  Ge.inlineRef = s;
  const i = /* @__PURE__ */ new Set([
    "$ref",
    "$recursiveRef",
    "$recursiveAnchor",
    "$dynamicRef",
    "$dynamicAnchor"
  ]);
  function a(E) {
    for (const _ in E) {
      if (i.has(_))
        return !0;
      const m = E[_];
      if (Array.isArray(m) && m.some(a) || typeof m == "object" && a(m))
        return !0;
    }
    return !1;
  }
  function o(E) {
    let _ = 0;
    for (const m in E) {
      if (m === "$ref")
        return 1 / 0;
      if (_++, !n.has(m) && (typeof E[m] == "object" && (0, t.eachItem)(E[m], (h) => _ += o(h)), _ === 1 / 0))
        return 1 / 0;
    }
    return _;
  }
  function c(E, _ = "", m) {
    m !== !1 && (_ = p(_));
    const h = E.parse(_);
    return u(E, h);
  }
  Ge.getFullPath = c;
  function u(E, _) {
    return E.serialize(_).split("#")[0] + "#";
  }
  Ge._getFullPath = u;
  const l = /#\/?$/;
  function p(E) {
    return E ? E.replace(l, "") : "";
  }
  Ge.normalizeId = p;
  function v(E, _, m) {
    return m = p(m), E.resolve(_, m);
  }
  Ge.resolveUrl = v;
  const w = /^[a-z_][-a-z0-9._]*$/i;
  function b(E, _) {
    if (typeof E == "boolean")
      return {};
    const { schemaId: m, uriResolver: h } = this.opts, g = p(E[m] || _), $ = { "": g }, d = c(h, g, !1), f = {}, y = /* @__PURE__ */ new Set();
    return r(E, { allKeys: !0 }, (N, R, j, F) => {
      if (F === void 0)
        return;
      const H = d + R;
      let se = $[F];
      typeof N[m] == "string" && (se = ke.call(this, N[m])), je.call(this, N.$anchor), je.call(this, N.$dynamicAnchor), $[R] = se;
      function ke(fe) {
        const Je = this.opts.uriResolver.resolve;
        if (fe = p(se ? Je(se, fe) : fe), y.has(fe))
          throw I(fe);
        y.add(fe);
        let L = this.refs[fe];
        return typeof L == "string" && (L = this.refs[L]), typeof L == "object" ? k(N, L.schema, fe) : fe !== p(H) && (fe[0] === "#" ? (k(N, f[fe], fe), f[fe] = N) : this.refs[fe] = H), fe;
      }
      function je(fe) {
        if (typeof fe == "string") {
          if (!w.test(fe))
            throw new Error(`invalid anchor "${fe}"`);
          ke.call(this, `#${fe}`);
        }
      }
    }), f;
    function k(N, R, j) {
      if (R !== void 0 && !e(N, R))
        throw I(j);
    }
    function I(N) {
      return new Error(`reference "${N}" resolves to more than one schema`);
    }
  }
  return Ge.getSchemaRefs = b, Ge;
}
var Ao;
function ms() {
  if (Ao) return $t;
  Ao = 1, Object.defineProperty($t, "__esModule", { value: !0 }), $t.getData = $t.KeywordCxt = $t.validateFunctionCode = void 0;
  const t = /* @__PURE__ */ Sy(), e = /* @__PURE__ */ is(), r = /* @__PURE__ */ dl(), n = /* @__PURE__ */ is(), s = /* @__PURE__ */ ky(), i = /* @__PURE__ */ $y(), a = /* @__PURE__ */ Ey(), o = /* @__PURE__ */ oe(), c = /* @__PURE__ */ Dt(), u = /* @__PURE__ */ ps(), l = /* @__PURE__ */ pe(), p = /* @__PURE__ */ fs();
  function v(P) {
    if (d(P) && (y(P), $(P))) {
      _(P);
      return;
    }
    w(P, () => (0, t.topBoolOrEmptySchema)(P));
  }
  $t.validateFunctionCode = v;
  function w({ gen: P, validateName: C, schema: M, schemaEnv: V, opts: Y }, ce) {
    Y.code.es5 ? P.func(C, (0, o._)`${c.default.data}, ${c.default.valCxt}`, V.$async, () => {
      P.code((0, o._)`"use strict"; ${h(M, Y)}`), E(P, Y), P.code(ce);
    }) : P.func(C, (0, o._)`${c.default.data}, ${b(Y)}`, V.$async, () => P.code(h(M, Y)).code(ce));
  }
  function b(P) {
    return (0, o._)`{${c.default.instancePath}="", ${c.default.parentData}, ${c.default.parentDataProperty}, ${c.default.rootData}=${c.default.data}${P.dynamicRef ? (0, o._)`, ${c.default.dynamicAnchors}={}` : o.nil}}={}`;
  }
  function E(P, C) {
    P.if(c.default.valCxt, () => {
      P.var(c.default.instancePath, (0, o._)`${c.default.valCxt}.${c.default.instancePath}`), P.var(c.default.parentData, (0, o._)`${c.default.valCxt}.${c.default.parentData}`), P.var(c.default.parentDataProperty, (0, o._)`${c.default.valCxt}.${c.default.parentDataProperty}`), P.var(c.default.rootData, (0, o._)`${c.default.valCxt}.${c.default.rootData}`), C.dynamicRef && P.var(c.default.dynamicAnchors, (0, o._)`${c.default.valCxt}.${c.default.dynamicAnchors}`);
    }, () => {
      P.var(c.default.instancePath, (0, o._)`""`), P.var(c.default.parentData, (0, o._)`undefined`), P.var(c.default.parentDataProperty, (0, o._)`undefined`), P.var(c.default.rootData, c.default.data), C.dynamicRef && P.var(c.default.dynamicAnchors, (0, o._)`{}`);
    });
  }
  function _(P) {
    const { schema: C, opts: M, gen: V } = P;
    w(P, () => {
      M.$comment && C.$comment && F(P), N(P), V.let(c.default.vErrors, null), V.let(c.default.errors, 0), M.unevaluated && m(P), k(P), H(P);
    });
  }
  function m(P) {
    const { gen: C, validateName: M } = P;
    P.evaluated = C.const("evaluated", (0, o._)`${M}.evaluated`), C.if((0, o._)`${P.evaluated}.dynamicProps`, () => C.assign((0, o._)`${P.evaluated}.props`, (0, o._)`undefined`)), C.if((0, o._)`${P.evaluated}.dynamicItems`, () => C.assign((0, o._)`${P.evaluated}.items`, (0, o._)`undefined`));
  }
  function h(P, C) {
    const M = typeof P == "object" && P[C.schemaId];
    return M && (C.code.source || C.code.process) ? (0, o._)`/*# sourceURL=${M} */` : o.nil;
  }
  function g(P, C) {
    if (d(P) && (y(P), $(P))) {
      f(P, C);
      return;
    }
    (0, t.boolOrEmptySchema)(P, C);
  }
  function $({ schema: P, self: C }) {
    if (typeof P == "boolean")
      return !P;
    for (const M in P)
      if (C.RULES.all[M])
        return !0;
    return !1;
  }
  function d(P) {
    return typeof P.schema != "boolean";
  }
  function f(P, C) {
    const { schema: M, gen: V, opts: Y } = P;
    Y.$comment && M.$comment && F(P), R(P), j(P);
    const ce = V.const("_errs", c.default.errors);
    k(P, ce), V.var(C, (0, o._)`${ce} === ${c.default.errors}`);
  }
  function y(P) {
    (0, l.checkUnknownRules)(P), I(P);
  }
  function k(P, C) {
    if (P.opts.jtd)
      return ke(P, [], !1, C);
    const M = (0, e.getSchemaTypes)(P.schema), V = (0, e.coerceAndCheckDataType)(P, M);
    ke(P, M, !V, C);
  }
  function I(P) {
    const { schema: C, errSchemaPath: M, opts: V, self: Y } = P;
    C.$ref && V.ignoreKeywordsWithRef && (0, l.schemaHasRulesButRef)(C, Y.RULES) && Y.logger.warn(`$ref: keywords ignored in schema at path "${M}"`);
  }
  function N(P) {
    const { schema: C, opts: M } = P;
    C.default !== void 0 && M.useDefaults && M.strictSchema && (0, l.checkStrictMode)(P, "default is ignored in the schema root");
  }
  function R(P) {
    const C = P.schema[P.opts.schemaId];
    C && (P.baseId = (0, u.resolveUrl)(P.opts.uriResolver, P.baseId, C));
  }
  function j(P) {
    if (P.schema.$async && !P.schemaEnv.$async)
      throw new Error("async schema in sync schema");
  }
  function F({ gen: P, schemaEnv: C, schema: M, errSchemaPath: V, opts: Y }) {
    const ce = M.$comment;
    if (Y.$comment === !0)
      P.code((0, o._)`${c.default.self}.logger.log(${ce})`);
    else if (typeof Y.$comment == "function") {
      const Ue = (0, o.str)`${V}/$comment`, ut = P.scopeValue("root", { ref: C.root });
      P.code((0, o._)`${c.default.self}.opts.$comment(${ce}, ${Ue}, ${ut}.schema)`);
    }
  }
  function H(P) {
    const { gen: C, schemaEnv: M, validateName: V, ValidationError: Y, opts: ce } = P;
    M.$async ? C.if((0, o._)`${c.default.errors} === 0`, () => C.return(c.default.data), () => C.throw((0, o._)`new ${Y}(${c.default.vErrors})`)) : (C.assign((0, o._)`${V}.errors`, c.default.vErrors), ce.unevaluated && se(P), C.return((0, o._)`${c.default.errors} === 0`));
  }
  function se({ gen: P, evaluated: C, props: M, items: V }) {
    M instanceof o.Name && P.assign((0, o._)`${C}.props`, M), V instanceof o.Name && P.assign((0, o._)`${C}.items`, V);
  }
  function ke(P, C, M, V) {
    const { gen: Y, schema: ce, data: Ue, allErrors: ut, opts: Be, self: Qe } = P, { RULES: Le } = Qe;
    if (ce.$ref && (Be.ignoreKeywordsWithRef || !(0, l.schemaHasRulesButRef)(ce, Le))) {
      Y.block(() => J(P, "$ref", Le.all.$ref.definition));
      return;
    }
    Be.jtd || fe(P, C), Y.block(() => {
      for (const st of Le.rules)
        Yt(st);
      Yt(Le.post);
    });
    function Yt(st) {
      (0, r.shouldUseGroup)(ce, st) && (st.type ? (Y.if((0, n.checkDataType)(st.type, Ue, Be.strictNumbers)), je(P, st), C.length === 1 && C[0] === st.type && M && (Y.else(), (0, n.reportTypeError)(P)), Y.endIf()) : je(P, st), ut || Y.if((0, o._)`${c.default.errors} === ${V || 0}`));
    }
  }
  function je(P, C) {
    const { gen: M, schema: V, opts: { useDefaults: Y } } = P;
    Y && (0, s.assignDefaults)(P, C.type), M.block(() => {
      for (const ce of C.rules)
        (0, r.shouldUseRule)(V, ce) && J(P, ce.keyword, ce.definition, C.type);
    });
  }
  function fe(P, C) {
    P.schemaEnv.meta || !P.opts.strictTypes || (Je(P, C), P.opts.allowUnionTypes || L(P, C), O(P, P.dataTypes));
  }
  function Je(P, C) {
    if (C.length) {
      if (!P.dataTypes.length) {
        P.dataTypes = C;
        return;
      }
      C.forEach((M) => {
        A(P.dataTypes, M) || T(P, `type "${M}" not allowed by context "${P.dataTypes.join(",")}"`);
      }), S(P, C);
    }
  }
  function L(P, C) {
    C.length > 1 && !(C.length === 2 && C.includes("null")) && T(P, "use allowUnionTypes to allow union type keyword");
  }
  function O(P, C) {
    const M = P.self.RULES.all;
    for (const V in M) {
      const Y = M[V];
      if (typeof Y == "object" && (0, r.shouldUseRule)(P.schema, Y)) {
        const { type: ce } = Y.definition;
        ce.length && !ce.some((Ue) => D(C, Ue)) && T(P, `missing type "${ce.join(",")}" for keyword "${V}"`);
      }
    }
  }
  function D(P, C) {
    return P.includes(C) || C === "number" && P.includes("integer");
  }
  function A(P, C) {
    return P.includes(C) || C === "integer" && P.includes("number");
  }
  function S(P, C) {
    const M = [];
    for (const V of P.dataTypes)
      A(C, V) ? M.push(V) : C.includes("integer") && V === "number" && M.push("integer");
    P.dataTypes = M;
  }
  function T(P, C) {
    const M = P.schemaEnv.baseId + P.errSchemaPath;
    C += ` at "${M}" (strictTypes)`, (0, l.checkStrictMode)(P, C, P.opts.strictTypes);
  }
  class q {
    constructor(C, M, V) {
      if ((0, i.validateKeywordUsage)(C, M, V), this.gen = C.gen, this.allErrors = C.allErrors, this.keyword = V, this.data = C.data, this.schema = C.schema[V], this.$data = M.$data && C.opts.$data && this.schema && this.schema.$data, this.schemaValue = (0, l.schemaRefOrVal)(C, this.schema, V, this.$data), this.schemaType = M.schemaType, this.parentSchema = C.schema, this.params = {}, this.it = C, this.def = M, this.$data)
        this.schemaCode = C.gen.const("vSchema", de(this.$data, C));
      else if (this.schemaCode = this.schemaValue, !(0, i.validSchemaType)(this.schema, M.schemaType, M.allowUndefined))
        throw new Error(`${V} value must be ${JSON.stringify(M.schemaType)}`);
      ("code" in M ? M.trackErrors : M.errors !== !1) && (this.errsCount = C.gen.const("_errs", c.default.errors));
    }
    result(C, M, V) {
      this.failResult((0, o.not)(C), M, V);
    }
    failResult(C, M, V) {
      this.gen.if(C), V ? V() : this.error(), M ? (this.gen.else(), M(), this.allErrors && this.gen.endIf()) : this.allErrors ? this.gen.endIf() : this.gen.else();
    }
    pass(C, M) {
      this.failResult((0, o.not)(C), void 0, M);
    }
    fail(C) {
      if (C === void 0) {
        this.error(), this.allErrors || this.gen.if(!1);
        return;
      }
      this.gen.if(C), this.error(), this.allErrors ? this.gen.endIf() : this.gen.else();
    }
    fail$data(C) {
      if (!this.$data)
        return this.fail(C);
      const { schemaCode: M } = this;
      this.fail((0, o._)`${M} !== undefined && (${(0, o.or)(this.invalid$data(), C)})`);
    }
    error(C, M, V) {
      if (M) {
        this.setParams(M), this._error(C, V), this.setParams({});
        return;
      }
      this._error(C, V);
    }
    _error(C, M) {
      (C ? p.reportExtraError : p.reportError)(this, this.def.error, M);
    }
    $dataError() {
      (0, p.reportError)(this, this.def.$dataError || p.keyword$DataError);
    }
    reset() {
      if (this.errsCount === void 0)
        throw new Error('add "trackErrors" to keyword definition');
      (0, p.resetErrorsCount)(this.gen, this.errsCount);
    }
    ok(C) {
      this.allErrors || this.gen.if(C);
    }
    setParams(C, M) {
      M ? Object.assign(this.params, C) : this.params = C;
    }
    block$data(C, M, V = o.nil) {
      this.gen.block(() => {
        this.check$data(C, V), M();
      });
    }
    check$data(C = o.nil, M = o.nil) {
      if (!this.$data)
        return;
      const { gen: V, schemaCode: Y, schemaType: ce, def: Ue } = this;
      V.if((0, o.or)((0, o._)`${Y} === undefined`, M)), C !== o.nil && V.assign(C, !0), (ce.length || Ue.validateSchema) && (V.elseIf(this.invalid$data()), this.$dataError(), C !== o.nil && V.assign(C, !1)), V.else();
    }
    invalid$data() {
      const { gen: C, schemaCode: M, schemaType: V, def: Y, it: ce } = this;
      return (0, o.or)(Ue(), ut());
      function Ue() {
        if (V.length) {
          if (!(M instanceof o.Name))
            throw new Error("ajv implementation error");
          const Be = Array.isArray(V) ? V : [V];
          return (0, o._)`${(0, n.checkDataTypes)(Be, M, ce.opts.strictNumbers, n.DataType.Wrong)}`;
        }
        return o.nil;
      }
      function ut() {
        if (Y.validateSchema) {
          const Be = C.scopeValue("validate$data", { ref: Y.validateSchema });
          return (0, o._)`!${Be}(${M})`;
        }
        return o.nil;
      }
    }
    subschema(C, M) {
      const V = (0, a.getSubschema)(this.it, C);
      (0, a.extendSubschemaData)(V, this.it, C), (0, a.extendSubschemaMode)(V, C);
      const Y = { ...this.it, ...V, items: void 0, props: void 0 };
      return g(Y, M), Y;
    }
    mergeEvaluated(C, M) {
      const { it: V, gen: Y } = this;
      V.opts.unevaluated && (V.props !== !0 && C.props !== void 0 && (V.props = l.mergeEvaluated.props(Y, C.props, V.props, M)), V.items !== !0 && C.items !== void 0 && (V.items = l.mergeEvaluated.items(Y, C.items, V.items, M)));
    }
    mergeValidEvaluated(C, M) {
      const { it: V, gen: Y } = this;
      if (V.opts.unevaluated && (V.props !== !0 || V.items !== !0))
        return Y.if(M, () => this.mergeEvaluated(C, o.Name)), !0;
    }
  }
  $t.KeywordCxt = q;
  function J(P, C, M, V) {
    const Y = new q(P, M, C);
    "code" in M ? M.code(Y, V) : Y.$data && M.validate ? (0, i.funcKeywordCode)(Y, M) : "macro" in M ? (0, i.macroKeywordCode)(Y, M) : (M.compile || M.validate) && (0, i.funcKeywordCode)(Y, M);
  }
  const te = /^\/(?:[^~]|~0|~1)*$/, _e = /^([0-9]+)(#|\/(?:[^~]|~0|~1)*)?$/;
  function de(P, { dataLevel: C, dataNames: M, dataPathArr: V }) {
    let Y, ce;
    if (P === "")
      return c.default.rootData;
    if (P[0] === "/") {
      if (!te.test(P))
        throw new Error(`Invalid JSON-pointer: ${P}`);
      Y = P, ce = c.default.rootData;
    } else {
      const Qe = _e.exec(P);
      if (!Qe)
        throw new Error(`Invalid JSON-pointer: ${P}`);
      const Le = +Qe[1];
      if (Y = Qe[2], Y === "#") {
        if (Le >= C)
          throw new Error(Be("property/index", Le));
        return V[C - Le];
      }
      if (Le > C)
        throw new Error(Be("data", Le));
      if (ce = M[C - Le], !Y)
        return ce;
    }
    let Ue = ce;
    const ut = Y.split("/");
    for (const Qe of ut)
      Qe && (ce = (0, o._)`${ce}${(0, o.getProperty)((0, l.unescapeJsonPointer)(Qe))}`, Ue = (0, o._)`${Ue} && ${ce}`);
    return Ue;
    function Be(Qe, Le) {
      return `Cannot access ${Qe} ${Le} levels up, current level is ${C}`;
    }
  }
  return $t.getData = de, $t;
}
var Yr = {}, No;
function ia() {
  if (No) return Yr;
  No = 1, Object.defineProperty(Yr, "__esModule", { value: !0 });
  class t extends Error {
    constructor(r) {
      super("validation failed"), this.errors = r, this.ajv = this.validation = !0;
    }
  }
  return Yr.default = t, Yr;
}
var Xr = {}, xo;
function gs() {
  if (xo) return Xr;
  xo = 1, Object.defineProperty(Xr, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ ps();
  class e extends Error {
    constructor(n, s, i, a) {
      super(a || `can't resolve reference ${i} from id ${s}`), this.missingRef = (0, t.resolveUrl)(n, s, i), this.missingSchema = (0, t.normalizeId)((0, t.getFullPath)(n, this.missingRef));
    }
  }
  return Xr.default = e, Xr;
}
var tt = {}, jo;
function aa() {
  if (jo) return tt;
  jo = 1, Object.defineProperty(tt, "__esModule", { value: !0 }), tt.resolveSchema = tt.getCompilingSchema = tt.resolveRef = tt.compileSchema = tt.SchemaEnv = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ ia(), r = /* @__PURE__ */ Dt(), n = /* @__PURE__ */ ps(), s = /* @__PURE__ */ pe(), i = /* @__PURE__ */ ms();
  class a {
    constructor(m) {
      var h;
      this.refs = {}, this.dynamicAnchors = {};
      let g;
      typeof m.schema == "object" && (g = m.schema), this.schema = m.schema, this.schemaId = m.schemaId, this.root = m.root || this, this.baseId = (h = m.baseId) !== null && h !== void 0 ? h : (0, n.normalizeId)(g == null ? void 0 : g[m.schemaId || "$id"]), this.schemaPath = m.schemaPath, this.localRefs = m.localRefs, this.meta = m.meta, this.$async = g == null ? void 0 : g.$async, this.refs = {};
    }
  }
  tt.SchemaEnv = a;
  function o(_) {
    const m = l.call(this, _);
    if (m)
      return m;
    const h = (0, n.getFullPath)(this.opts.uriResolver, _.root.baseId), { es5: g, lines: $ } = this.opts.code, { ownProperties: d } = this.opts, f = new t.CodeGen(this.scope, { es5: g, lines: $, ownProperties: d });
    let y;
    _.$async && (y = f.scopeValue("Error", {
      ref: e.default,
      code: (0, t._)`require("ajv/dist/runtime/validation_error").default`
    }));
    const k = f.scopeName("validate");
    _.validateName = k;
    const I = {
      gen: f,
      allErrors: this.opts.allErrors,
      data: r.default.data,
      parentData: r.default.parentData,
      parentDataProperty: r.default.parentDataProperty,
      dataNames: [r.default.data],
      dataPathArr: [t.nil],
      // TODO can its length be used as dataLevel if nil is removed?
      dataLevel: 0,
      dataTypes: [],
      definedProperties: /* @__PURE__ */ new Set(),
      topSchemaRef: f.scopeValue("schema", this.opts.code.source === !0 ? { ref: _.schema, code: (0, t.stringify)(_.schema) } : { ref: _.schema }),
      validateName: k,
      ValidationError: y,
      schema: _.schema,
      schemaEnv: _,
      rootId: h,
      baseId: _.baseId || h,
      schemaPath: t.nil,
      errSchemaPath: _.schemaPath || (this.opts.jtd ? "" : "#"),
      errorPath: (0, t._)`""`,
      opts: this.opts,
      self: this
    };
    let N;
    try {
      this._compilations.add(_), (0, i.validateFunctionCode)(I), f.optimize(this.opts.code.optimize);
      const R = f.toString();
      N = `${f.scopeRefs(r.default.scope)}return ${R}`, this.opts.code.process && (N = this.opts.code.process(N, _));
      const F = new Function(`${r.default.self}`, `${r.default.scope}`, N)(this, this.scope.get());
      if (this.scope.value(k, { ref: F }), F.errors = null, F.schema = _.schema, F.schemaEnv = _, _.$async && (F.$async = !0), this.opts.code.source === !0 && (F.source = { validateName: k, validateCode: R, scopeValues: f._values }), this.opts.unevaluated) {
        const { props: H, items: se } = I;
        F.evaluated = {
          props: H instanceof t.Name ? void 0 : H,
          items: se instanceof t.Name ? void 0 : se,
          dynamicProps: H instanceof t.Name,
          dynamicItems: se instanceof t.Name
        }, F.source && (F.source.evaluated = (0, t.stringify)(F.evaluated));
      }
      return _.validate = F, _;
    } catch (R) {
      throw delete _.validate, delete _.validateName, N && this.logger.error("Error compiling schema, function code:", N), R;
    } finally {
      this._compilations.delete(_);
    }
  }
  tt.compileSchema = o;
  function c(_, m, h) {
    var g;
    h = (0, n.resolveUrl)(this.opts.uriResolver, m, h);
    const $ = _.refs[h];
    if ($)
      return $;
    let d = v.call(this, _, h);
    if (d === void 0) {
      const f = (g = _.localRefs) === null || g === void 0 ? void 0 : g[h], { schemaId: y } = this.opts;
      f && (d = new a({ schema: f, schemaId: y, root: _, baseId: m }));
    }
    if (d !== void 0)
      return _.refs[h] = u.call(this, d);
  }
  tt.resolveRef = c;
  function u(_) {
    return (0, n.inlineRef)(_.schema, this.opts.inlineRefs) ? _.schema : _.validate ? _ : o.call(this, _);
  }
  function l(_) {
    for (const m of this._compilations)
      if (p(m, _))
        return m;
  }
  tt.getCompilingSchema = l;
  function p(_, m) {
    return _.schema === m.schema && _.root === m.root && _.baseId === m.baseId;
  }
  function v(_, m) {
    let h;
    for (; typeof (h = this.refs[m]) == "string"; )
      m = h;
    return h || this.schemas[m] || w.call(this, _, m);
  }
  function w(_, m) {
    const h = this.opts.uriResolver.parse(m), g = (0, n._getFullPath)(this.opts.uriResolver, h);
    let $ = (0, n.getFullPath)(this.opts.uriResolver, _.baseId, void 0);
    if (Object.keys(_.schema).length > 0 && g === $)
      return E.call(this, h, _);
    const d = (0, n.normalizeId)(g), f = this.refs[d] || this.schemas[d];
    if (typeof f == "string") {
      const y = w.call(this, _, f);
      return typeof (y == null ? void 0 : y.schema) != "object" ? void 0 : E.call(this, h, y);
    }
    if (typeof (f == null ? void 0 : f.schema) == "object") {
      if (f.validate || o.call(this, f), d === (0, n.normalizeId)(m)) {
        const { schema: y } = f, { schemaId: k } = this.opts, I = y[k];
        return I && ($ = (0, n.resolveUrl)(this.opts.uriResolver, $, I)), new a({ schema: y, schemaId: k, root: _, baseId: $ });
      }
      return E.call(this, h, f);
    }
  }
  tt.resolveSchema = w;
  const b = /* @__PURE__ */ new Set([
    "properties",
    "patternProperties",
    "enum",
    "dependencies",
    "definitions"
  ]);
  function E(_, { baseId: m, schema: h, root: g }) {
    var $;
    if ((($ = _.fragment) === null || $ === void 0 ? void 0 : $[0]) !== "/")
      return;
    for (const y of _.fragment.slice(1).split("/")) {
      if (typeof h == "boolean")
        return;
      const k = h[(0, s.unescapeFragment)(y)];
      if (k === void 0)
        return;
      h = k;
      const I = typeof h == "object" && h[this.opts.schemaId];
      !b.has(y) && I && (m = (0, n.resolveUrl)(this.opts.uriResolver, m, I));
    }
    let d;
    if (typeof h != "boolean" && h.$ref && !(0, s.schemaHasRulesButRef)(h, this.RULES)) {
      const y = (0, n.resolveUrl)(this.opts.uriResolver, m, h.$ref);
      d = w.call(this, g, y);
    }
    const { schemaId: f } = this.opts;
    if (d = d || new a({ schema: h, schemaId: f, root: g, baseId: m }), d.schema !== d.root.schema)
      return d;
  }
  return tt;
}
const Ry = "https://raw.githubusercontent.com/ajv-validator/ajv/master/lib/refs/data.json#", Iy = "Meta-schema for $data reference (JSON AnySchema extension proposal)", Py = "object", Oy = ["$data"], Cy = { $data: { type: "string", anyOf: [{ format: "relative-json-pointer" }, { format: "json-pointer" }] } }, Ay = !1, Ny = {
  $id: Ry,
  description: Iy,
  type: Py,
  required: Oy,
  properties: Cy,
  additionalProperties: Ay
};
var en = {}, vr = { exports: {} }, Ls, zo;
function fl() {
  if (zo) return Ls;
  zo = 1;
  const t = RegExp.prototype.test.bind(/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/iu), e = RegExp.prototype.test.bind(/^(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]\d|\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]\d|\d)$/u), r = RegExp.prototype.test.bind(/^[\da-f]{2}$/iu), n = RegExp.prototype.test.bind(/^[\da-z\-._~]$/iu), s = RegExp.prototype.test.bind(/^[\da-z\-._~!$&'()*+,;=:@/]$/iu);
  function i(d) {
    let f = "", y = 0, k = 0;
    for (k = 0; k < d.length; k++)
      if (y = d[k].charCodeAt(0), y !== 48) {
        if (!(y >= 48 && y <= 57 || y >= 65 && y <= 70 || y >= 97 && y <= 102))
          return "";
        f += d[k];
        break;
      }
    for (k += 1; k < d.length; k++) {
      if (y = d[k].charCodeAt(0), !(y >= 48 && y <= 57 || y >= 65 && y <= 70 || y >= 97 && y <= 102))
        return "";
      f += d[k];
    }
    return f;
  }
  const a = RegExp.prototype.test.bind(/[^!"$&'()*+,\-.;=_`a-z{}~]/u);
  function o(d) {
    return d.length = 0, !0;
  }
  function c(d, f, y) {
    if (d.length) {
      const k = i(d);
      if (k !== "")
        f.push(k);
      else
        return y.error = !0, !1;
      d.length = 0;
    }
    return !0;
  }
  function u(d) {
    let f = 0;
    const y = { error: !1, address: "", zone: "" }, k = [], I = [];
    let N = !1, R = !1, j = c;
    for (let F = 0; F < d.length; F++) {
      const H = d[F];
      if (!(H === "[" || H === "]"))
        if (H === ":") {
          if (N === !0 && (R = !0), !j(I, k, y))
            break;
          if (++f > 7) {
            y.error = !0;
            break;
          }
          F > 0 && d[F - 1] === ":" && (N = !0), k.push(":");
          continue;
        } else if (H === "%") {
          if (!j(I, k, y))
            break;
          j = o;
        } else {
          I.push(H);
          continue;
        }
    }
    return I.length && (j === o ? y.zone = I.join("") : R ? k.push(I.join("")) : k.push(i(I))), y.address = k.join(""), y;
  }
  function l(d) {
    if (p(d, ":") < 2)
      return { host: d, isIPV6: !1 };
    const f = u(d);
    if (f.error)
      return { host: d, isIPV6: !1 };
    {
      let y = f.address, k = f.address;
      return f.zone && (y += "%" + f.zone, k += "%25" + f.zone), { host: y, isIPV6: !0, escapedHost: k };
    }
  }
  function p(d, f) {
    let y = 0;
    for (let k = 0; k < d.length; k++)
      d[k] === f && y++;
    return y;
  }
  function v(d) {
    let f = d;
    const y = [];
    let k = -1, I = 0;
    for (; I = f.length; ) {
      if (I === 1) {
        if (f === ".")
          break;
        if (f === "/") {
          y.push("/");
          break;
        } else {
          y.push(f);
          break;
        }
      } else if (I === 2) {
        if (f[0] === ".") {
          if (f[1] === ".")
            break;
          if (f[1] === "/") {
            f = f.slice(2);
            continue;
          }
        } else if (f[0] === "/" && (f[1] === "." || f[1] === "/")) {
          y.push("/");
          break;
        }
      } else if (I === 3 && f === "/..") {
        y.length !== 0 && y.pop(), y.push("/");
        break;
      }
      if (f[0] === ".") {
        if (f[1] === ".") {
          if (f[2] === "/") {
            f = f.slice(3);
            continue;
          }
        } else if (f[1] === "/") {
          f = f.slice(2);
          continue;
        }
      } else if (f[0] === "/" && f[1] === ".") {
        if (f[2] === "/") {
          f = f.slice(2);
          continue;
        } else if (f[2] === "." && f[3] === "/") {
          f = f.slice(3), y.length !== 0 && y.pop();
          continue;
        }
      }
      if ((k = f.indexOf("/", 1)) === -1) {
        y.push(f);
        break;
      } else
        y.push(f.slice(0, k)), f = f.slice(k);
    }
    return y.join("");
  }
  const w = { "@": "%40", "/": "%2F", "?": "%3F", "#": "%23", ":": "%3A" }, b = /[@/?#:]/g, E = /[@/?#]/g;
  function _(d, f) {
    const y = f ? E : b;
    return y.lastIndex = 0, d.replace(y, (k) => w[k]);
  }
  function m(d, f = !1) {
    if (d.indexOf("%") === -1)
      return d;
    let y = "";
    for (let k = 0; k < d.length; k++) {
      if (d[k] === "%" && k + 2 < d.length) {
        const I = d.slice(k + 1, k + 3);
        if (r(I)) {
          const N = I.toUpperCase(), R = String.fromCharCode(parseInt(N, 16));
          f && n(R) ? y += R : y += "%" + N, k += 2;
          continue;
        }
      }
      y += d[k];
    }
    return y;
  }
  function h(d) {
    let f = "";
    for (let y = 0; y < d.length; y++) {
      if (d[y] === "%" && y + 2 < d.length) {
        const k = d.slice(y + 1, y + 3);
        if (r(k)) {
          const I = k.toUpperCase(), N = String.fromCharCode(parseInt(I, 16));
          N !== "." && n(N) ? f += N : f += "%" + I, y += 2;
          continue;
        }
      }
      s(d[y]) ? f += d[y] : f += escape(d[y]);
    }
    return f;
  }
  function g(d) {
    let f = "";
    for (let y = 0; y < d.length; y++) {
      if (d[y] === "%" && y + 2 < d.length) {
        const k = d.slice(y + 1, y + 3);
        if (r(k)) {
          f += "%" + k.toUpperCase(), y += 2;
          continue;
        }
      }
      f += escape(d[y]);
    }
    return f;
  }
  function $(d) {
    const f = [];
    if (d.userinfo !== void 0 && (f.push(d.userinfo), f.push("@")), d.host !== void 0) {
      let y = unescape(d.host);
      if (!e(y)) {
        const k = l(y);
        k.isIPV6 === !0 ? y = `[${k.escapedHost}]` : y = _(y, !1);
      }
      f.push(y);
    }
    return (typeof d.port == "number" || typeof d.port == "string") && (f.push(":"), f.push(String(d.port))), f.length ? f.join("") : void 0;
  }
  return Ls = {
    nonSimpleDomain: a,
    recomposeAuthority: $,
    reescapeHostDelimiters: _,
    normalizePercentEncoding: m,
    normalizePathEncoding: h,
    escapePreservingEscapes: g,
    removeDotSegments: v,
    isIPv4: e,
    isUUID: t,
    normalizeIPv6: l,
    stringArrayToHexStripped: i
  }, Ls;
}
var Zs, qo;
function xy() {
  if (qo) return Zs;
  qo = 1;
  const { isUUID: t } = fl(), e = /([\da-z][\d\-a-z]{0,31}):((?:[\w!$'()*+,\-.:;=@]|%[\da-f]{2})+)/iu, r = (
    /** @type {const} */
    [
      "http",
      "https",
      "ws",
      "wss",
      "urn",
      "urn:uuid"
    ]
  );
  function n(d) {
    return r.indexOf(
      /** @type {*} */
      d
    ) !== -1;
  }
  function s(d) {
    return d.secure === !0 ? !0 : d.secure === !1 ? !1 : d.scheme ? d.scheme.length === 3 && (d.scheme[0] === "w" || d.scheme[0] === "W") && (d.scheme[1] === "s" || d.scheme[1] === "S") && (d.scheme[2] === "s" || d.scheme[2] === "S") : !1;
  }
  function i(d) {
    return d.host || (d.error = d.error || "HTTP URIs must have a host."), d;
  }
  function a(d) {
    const f = String(d.scheme).toLowerCase() === "https";
    return (d.port === (f ? 443 : 80) || d.port === "") && (d.port = void 0), d.path || (d.path = "/"), d;
  }
  function o(d) {
    return d.secure = s(d), d.resourceName = (d.path || "/") + (d.query ? "?" + d.query : ""), d.path = void 0, d.query = void 0, d;
  }
  function c(d) {
    if ((d.port === (s(d) ? 443 : 80) || d.port === "") && (d.port = void 0), typeof d.secure == "boolean" && (d.scheme = d.secure ? "wss" : "ws", d.secure = void 0), d.resourceName) {
      const [f, y] = d.resourceName.split("?");
      d.path = f && f !== "/" ? f : void 0, d.query = y, d.resourceName = void 0;
    }
    return d.fragment = void 0, d;
  }
  function u(d, f) {
    if (!d.path)
      return d.error = "URN can not be parsed", d;
    const y = d.path.match(e);
    if (y) {
      const k = f.scheme || d.scheme || "urn";
      d.nid = y[1].toLowerCase(), d.nss = y[2];
      const I = `${k}:${f.nid || d.nid}`, N = $(I);
      d.path = void 0, N && (d = N.parse(d, f));
    } else
      d.error = d.error || "URN can not be parsed.";
    return d;
  }
  function l(d, f) {
    if (d.nid === void 0)
      throw new Error("URN without nid cannot be serialized");
    const y = f.scheme || d.scheme || "urn", k = d.nid.toLowerCase(), I = `${y}:${f.nid || k}`, N = $(I);
    N && (d = N.serialize(d, f));
    const R = d, j = d.nss;
    return R.path = `${k || f.nid}:${j}`, f.skipEscape = !0, R;
  }
  function p(d, f) {
    const y = d;
    return y.uuid = y.nss, y.nss = void 0, !f.tolerant && (!y.uuid || !t(y.uuid)) && (y.error = y.error || "UUID is not valid."), y;
  }
  function v(d) {
    const f = d;
    return f.nss = (d.uuid || "").toLowerCase(), f;
  }
  const w = (
    /** @type {SchemeHandler} */
    {
      scheme: "http",
      domainHost: !0,
      parse: i,
      serialize: a
    }
  ), b = (
    /** @type {SchemeHandler} */
    {
      scheme: "https",
      domainHost: w.domainHost,
      parse: i,
      serialize: a
    }
  ), E = (
    /** @type {SchemeHandler} */
    {
      scheme: "ws",
      domainHost: !0,
      parse: o,
      serialize: c
    }
  ), _ = (
    /** @type {SchemeHandler} */
    {
      scheme: "wss",
      domainHost: E.domainHost,
      parse: E.parse,
      serialize: E.serialize
    }
  ), g = (
    /** @type {Record<SchemeName, SchemeHandler>} */
    {
      http: w,
      https: b,
      ws: E,
      wss: _,
      urn: (
        /** @type {SchemeHandler} */
        {
          scheme: "urn",
          parse: u,
          serialize: l,
          skipNormalize: !0
        }
      ),
      "urn:uuid": (
        /** @type {SchemeHandler} */
        {
          scheme: "urn:uuid",
          parse: p,
          serialize: v,
          skipNormalize: !0
        }
      )
    }
  );
  Object.setPrototypeOf(g, null);
  function $(d) {
    return d && (g[
      /** @type {SchemeName} */
      d
    ] || g[
      /** @type {SchemeName} */
      d.toLowerCase()
    ]) || void 0;
  }
  return Zs = {
    wsIsSecure: s,
    SCHEMES: g,
    isValidSchemeName: n,
    getSchemeHandler: $
  }, Zs;
}
var Mo;
function jy() {
  if (Mo) return vr.exports;
  Mo = 1;
  const { normalizeIPv6: t, removeDotSegments: e, recomposeAuthority: r, normalizePercentEncoding: n, normalizePathEncoding: s, escapePreservingEscapes: i, reescapeHostDelimiters: a, isIPv4: o, nonSimpleDomain: c } = fl(), { SCHEMES: u, getSchemeHandler: l } = xy();
  function p(k, I) {
    return typeof k == "string" ? k = /** @type {T} */
    $(k, I) : typeof k == "object" && (k = /** @type {T} */
    g(E(k, I), I)), k;
  }
  function v(k, I, N) {
    const R = N ? Object.assign({ scheme: "null" }, N) : { scheme: "null" }, j = w(g(k, R), g(I, R), R, !0);
    return R.skipEscape = !0, E(j, R);
  }
  function w(k, I, N, R) {
    const j = {};
    return R || (k = g(E(k, N), N), I = g(E(I, N), N)), N = N || {}, !N.tolerant && I.scheme ? (j.scheme = I.scheme, j.userinfo = I.userinfo, j.host = I.host, j.port = I.port, j.path = e(I.path || ""), j.query = I.query) : (I.userinfo !== void 0 || I.host !== void 0 || I.port !== void 0 ? (j.userinfo = I.userinfo, j.host = I.host, j.port = I.port, j.path = e(I.path || ""), j.query = I.query) : (I.path ? (I.path[0] === "/" ? j.path = e(I.path) : ((k.userinfo !== void 0 || k.host !== void 0 || k.port !== void 0) && !k.path ? j.path = "/" + I.path : k.path ? j.path = k.path.slice(0, k.path.lastIndexOf("/") + 1) + I.path : j.path = I.path, j.path = e(j.path)), j.query = I.query) : (j.path = k.path, I.query !== void 0 ? j.query = I.query : j.query = k.query), j.userinfo = k.userinfo, j.host = k.host, j.port = k.port), j.scheme = k.scheme), j.fragment = I.fragment, j;
  }
  function b(k, I, N) {
    const R = f(k, N), j = f(I, N);
    return R !== void 0 && j !== void 0 && R.toLowerCase() === j.toLowerCase();
  }
  function E(k, I) {
    const N = {
      host: k.host,
      scheme: k.scheme,
      userinfo: k.userinfo,
      port: k.port,
      path: k.path,
      query: k.query,
      nid: k.nid,
      nss: k.nss,
      uuid: k.uuid,
      fragment: k.fragment,
      reference: k.reference,
      resourceName: k.resourceName,
      secure: k.secure,
      error: ""
    }, R = Object.assign({}, I), j = [], F = l(R.scheme || N.scheme);
    F && F.serialize && F.serialize(N, R), N.path !== void 0 && (R.skipEscape ? N.path = n(N.path) : (N.path = i(N.path), N.scheme !== void 0 && (N.path = N.path.split("%3A").join(":")))), R.reference !== "suffix" && N.scheme && j.push(N.scheme, ":");
    const H = r(N);
    if (H !== void 0 && (R.reference !== "suffix" && j.push("//"), j.push(H), N.path && N.path[0] !== "/" && j.push("/")), N.path !== void 0) {
      let se = N.path;
      !R.absolutePath && (!F || !F.absolutePath) && (se = e(se)), H === void 0 && se[0] === "/" && se[1] === "/" && (se = "/%2F" + se.slice(2)), j.push(se);
    }
    return N.query !== void 0 && j.push("?", N.query), N.fragment !== void 0 && j.push("#", N.fragment), j.join("");
  }
  const _ = /^(?:([^#/:?]+):)?(?:\/\/((?:([^#/?@]*)@)?(\[[^#/?\]]+\]|[^#/:?]*)(?::(\d*))?))?([^#?]*)(?:\?([^#]*))?(?:#((?:.|[\n\r])*))?/u;
  function m(k, I) {
    if (I[2] !== void 0 && k.path && k.path[0] !== "/")
      return 'URI path must start with "/" when authority is present.';
    if (typeof k.port == "number" && (k.port < 0 || k.port > 65535))
      return "URI port is malformed.";
  }
  function h(k, I) {
    const N = Object.assign({}, I), R = {
      scheme: void 0,
      userinfo: void 0,
      host: "",
      port: void 0,
      path: "",
      query: void 0,
      fragment: void 0
    };
    let j = !1, F = !1;
    N.reference === "suffix" && (N.scheme ? k = N.scheme + ":" + k : k = "//" + k);
    const H = k.match(_);
    if (H) {
      R.scheme = H[1], R.userinfo = H[3], R.host = H[4], R.port = parseInt(H[5], 10), R.path = H[6] || "", R.query = H[7], R.fragment = H[8], isNaN(R.port) && (R.port = H[5]);
      const se = m(R, H);
      if (se !== void 0 && (R.error = R.error || se, j = !0), R.host)
        if (o(R.host) === !1) {
          const fe = t(R.host);
          R.host = fe.host.toLowerCase(), F = fe.isIPV6;
        } else
          F = !0;
      R.scheme === void 0 && R.userinfo === void 0 && R.host === void 0 && R.port === void 0 && R.query === void 0 && !R.path ? R.reference = "same-document" : R.scheme === void 0 ? R.reference = "relative" : R.fragment === void 0 ? R.reference = "absolute" : R.reference = "uri", N.reference && N.reference !== "suffix" && N.reference !== R.reference && (R.error = R.error || "URI is not a " + N.reference + " reference.");
      const ke = l(N.scheme || R.scheme);
      if (!N.unicodeSupport && (!ke || !ke.unicodeSupport) && R.host && (N.domainHost || ke && ke.domainHost) && F === !1 && c(R.host))
        try {
          R.host = URL.domainToASCII(R.host.toLowerCase());
        } catch (je) {
          R.error = R.error || "Host's domain name can not be converted to ASCII: " + je;
        }
      if ((!ke || ke && !ke.skipNormalize) && (k.indexOf("%") !== -1 && (R.scheme !== void 0 && (R.scheme = unescape(R.scheme)), R.host !== void 0 && (R.host = a(unescape(R.host), F))), R.path && (R.path = s(R.path)), R.fragment))
        try {
          R.fragment = encodeURI(decodeURIComponent(R.fragment));
        } catch {
          R.error = R.error || "URI malformed";
        }
      ke && ke.parse && ke.parse(R, N);
    } else
      R.error = R.error || "URI can not be parsed.";
    return { parsed: R, malformedAuthorityOrPort: j };
  }
  function g(k, I) {
    return h(k, I).parsed;
  }
  function $(k, I) {
    return d(k, I).normalized;
  }
  function d(k, I) {
    const { parsed: N, malformedAuthorityOrPort: R } = h(k, I);
    return {
      normalized: R ? k : E(N, I),
      malformedAuthorityOrPort: R
    };
  }
  function f(k, I) {
    if (typeof k == "string") {
      const { normalized: N, malformedAuthorityOrPort: R } = d(k, I);
      return R ? void 0 : N;
    }
    if (typeof k == "object")
      return E(k, I);
  }
  const y = {
    SCHEMES: u,
    normalize: p,
    resolve: v,
    resolveComponent: w,
    equal: b,
    serialize: E,
    parse: g
  };
  return vr.exports = y, vr.exports.default = y, vr.exports.fastUri = y, vr.exports;
}
var Do;
function zy() {
  if (Do) return en;
  Do = 1, Object.defineProperty(en, "__esModule", { value: !0 });
  const t = jy();
  return t.code = 'require("ajv/dist/runtime/uri").default', en.default = t, en;
}
var Uo;
function qy() {
  return Uo || (Uo = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.CodeGen = t.Name = t.nil = t.stringify = t.str = t._ = t.KeywordCxt = void 0;
    var e = /* @__PURE__ */ ms();
    Object.defineProperty(t, "KeywordCxt", { enumerable: !0, get: function() {
      return e.KeywordCxt;
    } });
    var r = /* @__PURE__ */ oe();
    Object.defineProperty(t, "_", { enumerable: !0, get: function() {
      return r._;
    } }), Object.defineProperty(t, "str", { enumerable: !0, get: function() {
      return r.str;
    } }), Object.defineProperty(t, "stringify", { enumerable: !0, get: function() {
      return r.stringify;
    } }), Object.defineProperty(t, "nil", { enumerable: !0, get: function() {
      return r.nil;
    } }), Object.defineProperty(t, "Name", { enumerable: !0, get: function() {
      return r.Name;
    } }), Object.defineProperty(t, "CodeGen", { enumerable: !0, get: function() {
      return r.CodeGen;
    } });
    const n = /* @__PURE__ */ ia(), s = /* @__PURE__ */ gs(), i = /* @__PURE__ */ ll(), a = /* @__PURE__ */ aa(), o = /* @__PURE__ */ oe(), c = /* @__PURE__ */ ps(), u = /* @__PURE__ */ is(), l = /* @__PURE__ */ pe(), p = Ny, v = /* @__PURE__ */ zy(), w = (L, O) => new RegExp(L, O);
    w.code = "new RegExp";
    const b = ["removeAdditional", "useDefaults", "coerceTypes"], E = /* @__PURE__ */ new Set([
      "validate",
      "serialize",
      "parse",
      "wrapper",
      "root",
      "schema",
      "keyword",
      "pattern",
      "formats",
      "validate$data",
      "func",
      "obj",
      "Error"
    ]), _ = {
      errorDataPath: "",
      format: "`validateFormats: false` can be used instead.",
      nullable: '"nullable" keyword is supported by default.',
      jsonPointers: "Deprecated jsPropertySyntax can be used instead.",
      extendRefs: "Deprecated ignoreKeywordsWithRef can be used instead.",
      missingRefs: "Pass empty schema with $id that should be ignored to ajv.addSchema.",
      processCode: "Use option `code: {process: (code, schemaEnv: object) => string}`",
      sourceCode: "Use option `code: {source: true}`",
      strictDefaults: "It is default now, see option `strict`.",
      strictKeywords: "It is default now, see option `strict`.",
      uniqueItems: '"uniqueItems" keyword is always validated.',
      unknownFormats: "Disable strict mode or pass `true` to `ajv.addFormat` (or `formats` option).",
      cache: "Map is used as cache, schema object as key.",
      serialize: "Map is used as cache, schema object as key.",
      ajvErrors: "It is default now."
    }, m = {
      ignoreKeywordsWithRef: "",
      jsPropertySyntax: "",
      unicode: '"minLength"/"maxLength" account for unicode characters by default.'
    }, h = 200;
    function g(L) {
      var O, D, A, S, T, q, J, te, _e, de, P, C, M, V, Y, ce, Ue, ut, Be, Qe, Le, Yt, st, _s, ys;
      const fr = L.strict, vs = (O = L.code) === null || O === void 0 ? void 0 : O.optimize, ca = vs === !0 || vs === void 0 ? 1 : vs || 0, ua = (A = (D = L.code) === null || D === void 0 ? void 0 : D.regExp) !== null && A !== void 0 ? A : w, kl = (S = L.uriResolver) !== null && S !== void 0 ? S : v.default;
      return {
        strictSchema: (q = (T = L.strictSchema) !== null && T !== void 0 ? T : fr) !== null && q !== void 0 ? q : !0,
        strictNumbers: (te = (J = L.strictNumbers) !== null && J !== void 0 ? J : fr) !== null && te !== void 0 ? te : !0,
        strictTypes: (de = (_e = L.strictTypes) !== null && _e !== void 0 ? _e : fr) !== null && de !== void 0 ? de : "log",
        strictTuples: (C = (P = L.strictTuples) !== null && P !== void 0 ? P : fr) !== null && C !== void 0 ? C : "log",
        strictRequired: (V = (M = L.strictRequired) !== null && M !== void 0 ? M : fr) !== null && V !== void 0 ? V : !1,
        code: L.code ? { ...L.code, optimize: ca, regExp: ua } : { optimize: ca, regExp: ua },
        loopRequired: (Y = L.loopRequired) !== null && Y !== void 0 ? Y : h,
        loopEnum: (ce = L.loopEnum) !== null && ce !== void 0 ? ce : h,
        meta: (Ue = L.meta) !== null && Ue !== void 0 ? Ue : !0,
        messages: (ut = L.messages) !== null && ut !== void 0 ? ut : !0,
        inlineRefs: (Be = L.inlineRefs) !== null && Be !== void 0 ? Be : !0,
        schemaId: (Qe = L.schemaId) !== null && Qe !== void 0 ? Qe : "$id",
        addUsedSchema: (Le = L.addUsedSchema) !== null && Le !== void 0 ? Le : !0,
        validateSchema: (Yt = L.validateSchema) !== null && Yt !== void 0 ? Yt : !0,
        validateFormats: (st = L.validateFormats) !== null && st !== void 0 ? st : !0,
        unicodeRegExp: (_s = L.unicodeRegExp) !== null && _s !== void 0 ? _s : !0,
        int32range: (ys = L.int32range) !== null && ys !== void 0 ? ys : !0,
        uriResolver: kl
      };
    }
    class $ {
      constructor(O = {}) {
        this.schemas = {}, this.refs = {}, this.formats = /* @__PURE__ */ Object.create(null), this._compilations = /* @__PURE__ */ new Set(), this._loading = {}, this._cache = /* @__PURE__ */ new Map(), O = this.opts = { ...O, ...g(O) };
        const { es5: D, lines: A } = this.opts.code;
        this.scope = new o.ValueScope({ scope: {}, prefixes: E, es5: D, lines: A }), this.logger = j(O.logger);
        const S = O.validateFormats;
        O.validateFormats = !1, this.RULES = (0, i.getRules)(), d.call(this, _, O, "NOT SUPPORTED"), d.call(this, m, O, "DEPRECATED", "warn"), this._metaOpts = N.call(this), O.formats && k.call(this), this._addVocabularies(), this._addDefaultMetaSchema(), O.keywords && I.call(this, O.keywords), typeof O.meta == "object" && this.addMetaSchema(O.meta), y.call(this), O.validateFormats = S;
      }
      _addVocabularies() {
        this.addKeyword("$async");
      }
      _addDefaultMetaSchema() {
        const { $data: O, meta: D, schemaId: A } = this.opts;
        let S = p;
        A === "id" && (S = { ...p }, S.id = S.$id, delete S.$id), D && O && this.addMetaSchema(S, S[A], !1);
      }
      defaultMeta() {
        const { meta: O, schemaId: D } = this.opts;
        return this.opts.defaultMeta = typeof O == "object" ? O[D] || O : void 0;
      }
      validate(O, D) {
        let A;
        if (typeof O == "string") {
          if (A = this.getSchema(O), !A)
            throw new Error(`no schema with key or ref "${O}"`);
        } else
          A = this.compile(O);
        const S = A(D);
        return "$async" in A || (this.errors = A.errors), S;
      }
      compile(O, D) {
        const A = this._addSchema(O, D);
        return A.validate || this._compileSchemaEnv(A);
      }
      compileAsync(O, D) {
        if (typeof this.opts.loadSchema != "function")
          throw new Error("options.loadSchema should be a function");
        const { loadSchema: A } = this.opts;
        return S.call(this, O, D);
        async function S(de, P) {
          await T.call(this, de.$schema);
          const C = this._addSchema(de, P);
          return C.validate || q.call(this, C);
        }
        async function T(de) {
          de && !this.getSchema(de) && await S.call(this, { $ref: de }, !0);
        }
        async function q(de) {
          try {
            return this._compileSchemaEnv(de);
          } catch (P) {
            if (!(P instanceof s.default))
              throw P;
            return J.call(this, P), await te.call(this, P.missingSchema), q.call(this, de);
          }
        }
        function J({ missingSchema: de, missingRef: P }) {
          if (this.refs[de])
            throw new Error(`AnySchema ${de} is loaded but ${P} cannot be resolved`);
        }
        async function te(de) {
          const P = await _e.call(this, de);
          this.refs[de] || await T.call(this, P.$schema), this.refs[de] || this.addSchema(P, de, D);
        }
        async function _e(de) {
          const P = this._loading[de];
          if (P)
            return P;
          try {
            return await (this._loading[de] = A(de));
          } finally {
            delete this._loading[de];
          }
        }
      }
      // Adds schema to the instance
      addSchema(O, D, A, S = this.opts.validateSchema) {
        if (Array.isArray(O)) {
          for (const q of O)
            this.addSchema(q, void 0, A, S);
          return this;
        }
        let T;
        if (typeof O == "object") {
          const { schemaId: q } = this.opts;
          if (T = O[q], T !== void 0 && typeof T != "string")
            throw new Error(`schema ${q} must be string`);
        }
        return D = (0, c.normalizeId)(D || T), this._checkUnique(D), this.schemas[D] = this._addSchema(O, A, D, S, !0), this;
      }
      // Add schema that will be used to validate other schemas
      // options in META_IGNORE_OPTIONS are alway set to false
      addMetaSchema(O, D, A = this.opts.validateSchema) {
        return this.addSchema(O, D, !0, A), this;
      }
      //  Validate schema against its meta-schema
      validateSchema(O, D) {
        if (typeof O == "boolean")
          return !0;
        let A;
        if (A = O.$schema, A !== void 0 && typeof A != "string")
          throw new Error("$schema must be a string");
        if (A = A || this.opts.defaultMeta || this.defaultMeta(), !A)
          return this.logger.warn("meta-schema not available"), this.errors = null, !0;
        const S = this.validate(A, O);
        if (!S && D) {
          const T = "schema is invalid: " + this.errorsText();
          if (this.opts.validateSchema === "log")
            this.logger.error(T);
          else
            throw new Error(T);
        }
        return S;
      }
      // Get compiled schema by `key` or `ref`.
      // (`key` that was passed to `addSchema` or full schema reference - `schema.$id` or resolved id)
      getSchema(O) {
        let D;
        for (; typeof (D = f.call(this, O)) == "string"; )
          O = D;
        if (D === void 0) {
          const { schemaId: A } = this.opts, S = new a.SchemaEnv({ schema: {}, schemaId: A });
          if (D = a.resolveSchema.call(this, S, O), !D)
            return;
          this.refs[O] = D;
        }
        return D.validate || this._compileSchemaEnv(D);
      }
      // Remove cached schema(s).
      // If no parameter is passed all schemas but meta-schemas are removed.
      // If RegExp is passed all schemas with key/id matching pattern but meta-schemas are removed.
      // Even if schema is referenced by other schemas it still can be removed as other schemas have local references.
      removeSchema(O) {
        if (O instanceof RegExp)
          return this._removeAllSchemas(this.schemas, O), this._removeAllSchemas(this.refs, O), this;
        switch (typeof O) {
          case "undefined":
            return this._removeAllSchemas(this.schemas), this._removeAllSchemas(this.refs), this._cache.clear(), this;
          case "string": {
            const D = f.call(this, O);
            return typeof D == "object" && this._cache.delete(D.schema), delete this.schemas[O], delete this.refs[O], this;
          }
          case "object": {
            const D = O;
            this._cache.delete(D);
            let A = O[this.opts.schemaId];
            return A && (A = (0, c.normalizeId)(A), delete this.schemas[A], delete this.refs[A]), this;
          }
          default:
            throw new Error("ajv.removeSchema: invalid parameter");
        }
      }
      // add "vocabulary" - a collection of keywords
      addVocabulary(O) {
        for (const D of O)
          this.addKeyword(D);
        return this;
      }
      addKeyword(O, D) {
        let A;
        if (typeof O == "string")
          A = O, typeof D == "object" && (this.logger.warn("these parameters are deprecated, see docs for addKeyword"), D.keyword = A);
        else if (typeof O == "object" && D === void 0) {
          if (D = O, A = D.keyword, Array.isArray(A) && !A.length)
            throw new Error("addKeywords: keyword must be string or non-empty array");
        } else
          throw new Error("invalid addKeywords parameters");
        if (H.call(this, A, D), !D)
          return (0, l.eachItem)(A, (T) => se.call(this, T)), this;
        je.call(this, D);
        const S = {
          ...D,
          type: (0, u.getJSONTypes)(D.type),
          schemaType: (0, u.getJSONTypes)(D.schemaType)
        };
        return (0, l.eachItem)(A, S.type.length === 0 ? (T) => se.call(this, T, S) : (T) => S.type.forEach((q) => se.call(this, T, S, q))), this;
      }
      getKeyword(O) {
        const D = this.RULES.all[O];
        return typeof D == "object" ? D.definition : !!D;
      }
      // Remove keyword
      removeKeyword(O) {
        const { RULES: D } = this;
        delete D.keywords[O], delete D.all[O];
        for (const A of D.rules) {
          const S = A.rules.findIndex((T) => T.keyword === O);
          S >= 0 && A.rules.splice(S, 1);
        }
        return this;
      }
      // Add format
      addFormat(O, D) {
        return typeof D == "string" && (D = new RegExp(D)), this.formats[O] = D, this;
      }
      errorsText(O = this.errors, { separator: D = ", ", dataVar: A = "data" } = {}) {
        return !O || O.length === 0 ? "No errors" : O.map((S) => `${A}${S.instancePath} ${S.message}`).reduce((S, T) => S + D + T);
      }
      $dataMetaSchema(O, D) {
        const A = this.RULES.all;
        O = JSON.parse(JSON.stringify(O));
        for (const S of D) {
          const T = S.split("/").slice(1);
          let q = O;
          for (const J of T)
            q = q[J];
          for (const J in A) {
            const te = A[J];
            if (typeof te != "object")
              continue;
            const { $data: _e } = te.definition, de = q[J];
            _e && de && (q[J] = Je(de));
          }
        }
        return O;
      }
      _removeAllSchemas(O, D) {
        for (const A in O) {
          const S = O[A];
          (!D || D.test(A)) && (typeof S == "string" ? delete O[A] : S && !S.meta && (this._cache.delete(S.schema), delete O[A]));
        }
      }
      _addSchema(O, D, A, S = this.opts.validateSchema, T = this.opts.addUsedSchema) {
        let q;
        const { schemaId: J } = this.opts;
        if (typeof O == "object")
          q = O[J];
        else {
          if (this.opts.jtd)
            throw new Error("schema must be object");
          if (typeof O != "boolean")
            throw new Error("schema must be object or boolean");
        }
        let te = this._cache.get(O);
        if (te !== void 0)
          return te;
        A = (0, c.normalizeId)(q || A);
        const _e = c.getSchemaRefs.call(this, O, A);
        return te = new a.SchemaEnv({ schema: O, schemaId: J, meta: D, baseId: A, localRefs: _e }), this._cache.set(te.schema, te), T && !A.startsWith("#") && (A && this._checkUnique(A), this.refs[A] = te), S && this.validateSchema(O, !0), te;
      }
      _checkUnique(O) {
        if (this.schemas[O] || this.refs[O])
          throw new Error(`schema with key or id "${O}" already exists`);
      }
      _compileSchemaEnv(O) {
        if (O.meta ? this._compileMetaSchema(O) : a.compileSchema.call(this, O), !O.validate)
          throw new Error("ajv implementation error");
        return O.validate;
      }
      _compileMetaSchema(O) {
        const D = this.opts;
        this.opts = this._metaOpts;
        try {
          a.compileSchema.call(this, O);
        } finally {
          this.opts = D;
        }
      }
    }
    $.ValidationError = n.default, $.MissingRefError = s.default, t.default = $;
    function d(L, O, D, A = "error") {
      for (const S in L) {
        const T = S;
        T in O && this.logger[A](`${D}: option ${S}. ${L[T]}`);
      }
    }
    function f(L) {
      return L = (0, c.normalizeId)(L), this.schemas[L] || this.refs[L];
    }
    function y() {
      const L = this.opts.schemas;
      if (L)
        if (Array.isArray(L))
          this.addSchema(L);
        else
          for (const O in L)
            this.addSchema(L[O], O);
    }
    function k() {
      for (const L in this.opts.formats) {
        const O = this.opts.formats[L];
        O && this.addFormat(L, O);
      }
    }
    function I(L) {
      if (Array.isArray(L)) {
        this.addVocabulary(L);
        return;
      }
      this.logger.warn("keywords option as map is deprecated, pass array");
      for (const O in L) {
        const D = L[O];
        D.keyword || (D.keyword = O), this.addKeyword(D);
      }
    }
    function N() {
      const L = { ...this.opts };
      for (const O of b)
        delete L[O];
      return L;
    }
    const R = { log() {
    }, warn() {
    }, error() {
    } };
    function j(L) {
      if (L === !1)
        return R;
      if (L === void 0)
        return console;
      if (L.log && L.warn && L.error)
        return L;
      throw new Error("logger must implement log, warn and error methods");
    }
    const F = /^[a-z_$][a-z0-9_$:-]*$/i;
    function H(L, O) {
      const { RULES: D } = this;
      if ((0, l.eachItem)(L, (A) => {
        if (D.keywords[A])
          throw new Error(`Keyword ${A} is already defined`);
        if (!F.test(A))
          throw new Error(`Keyword ${A} has invalid name`);
      }), !!O && O.$data && !("code" in O || "validate" in O))
        throw new Error('$data keyword must have "code" or "validate" function');
    }
    function se(L, O, D) {
      var A;
      const S = O == null ? void 0 : O.post;
      if (D && S)
        throw new Error('keyword with "post" flag cannot have "type"');
      const { RULES: T } = this;
      let q = S ? T.post : T.rules.find(({ type: te }) => te === D);
      if (q || (q = { type: D, rules: [] }, T.rules.push(q)), T.keywords[L] = !0, !O)
        return;
      const J = {
        keyword: L,
        definition: {
          ...O,
          type: (0, u.getJSONTypes)(O.type),
          schemaType: (0, u.getJSONTypes)(O.schemaType)
        }
      };
      O.before ? ke.call(this, q, J, O.before) : q.rules.push(J), T.all[L] = J, (A = O.implements) === null || A === void 0 || A.forEach((te) => this.addKeyword(te));
    }
    function ke(L, O, D) {
      const A = L.rules.findIndex((S) => S.keyword === D);
      A >= 0 ? L.rules.splice(A, 0, O) : (L.rules.push(O), this.logger.warn(`rule ${D} is not defined`));
    }
    function je(L) {
      let { metaSchema: O } = L;
      O !== void 0 && (L.$data && this.opts.$data && (O = Je(O)), L.validateSchema = this.compile(O, !0));
    }
    const fe = {
      $ref: "https://raw.githubusercontent.com/ajv-validator/ajv/master/lib/refs/data.json#"
    };
    function Je(L) {
      return { anyOf: [L, fe] };
    }
  })(xs)), xs;
}
var tn = {}, rn = {}, nn = {}, Lo;
function My() {
  if (Lo) return nn;
  Lo = 1, Object.defineProperty(nn, "__esModule", { value: !0 });
  const t = {
    keyword: "id",
    code() {
      throw new Error('NOT SUPPORTED: keyword "id", use "$id" for schema ID');
    }
  };
  return nn.default = t, nn;
}
var Ct = {}, Zo;
function Dy() {
  if (Zo) return Ct;
  Zo = 1, Object.defineProperty(Ct, "__esModule", { value: !0 }), Ct.callRef = Ct.getValidate = void 0;
  const t = /* @__PURE__ */ gs(), e = /* @__PURE__ */ pt(), r = /* @__PURE__ */ oe(), n = /* @__PURE__ */ Dt(), s = /* @__PURE__ */ aa(), i = /* @__PURE__ */ pe(), a = {
    keyword: "$ref",
    schemaType: "string",
    code(u) {
      const { gen: l, schema: p, it: v } = u, { baseId: w, schemaEnv: b, validateName: E, opts: _, self: m } = v, { root: h } = b;
      if ((p === "#" || p === "#/") && w === h.baseId)
        return $();
      const g = s.resolveRef.call(m, h, w, p);
      if (g === void 0)
        throw new t.default(v.opts.uriResolver, w, p);
      if (g instanceof s.SchemaEnv)
        return d(g);
      return f(g);
      function $() {
        if (b === h)
          return c(u, E, b, b.$async);
        const y = l.scopeValue("root", { ref: h });
        return c(u, (0, r._)`${y}.validate`, h, h.$async);
      }
      function d(y) {
        const k = o(u, y);
        c(u, k, y, y.$async);
      }
      function f(y) {
        const k = l.scopeValue("schema", _.code.source === !0 ? { ref: y, code: (0, r.stringify)(y) } : { ref: y }), I = l.name("valid"), N = u.subschema({
          schema: y,
          dataTypes: [],
          schemaPath: r.nil,
          topSchemaRef: k,
          errSchemaPath: p
        }, I);
        u.mergeEvaluated(N), u.ok(I);
      }
    }
  };
  function o(u, l) {
    const { gen: p } = u;
    return l.validate ? p.scopeValue("validate", { ref: l.validate }) : (0, r._)`${p.scopeValue("wrapper", { ref: l })}.validate`;
  }
  Ct.getValidate = o;
  function c(u, l, p, v) {
    const { gen: w, it: b } = u, { allErrors: E, schemaEnv: _, opts: m } = b, h = m.passContext ? n.default.this : r.nil;
    v ? g() : $();
    function g() {
      if (!_.$async)
        throw new Error("async schema referenced by sync schema");
      const y = w.let("valid");
      w.try(() => {
        w.code((0, r._)`await ${(0, e.callValidateCode)(u, l, h)}`), f(l), E || w.assign(y, !0);
      }, (k) => {
        w.if((0, r._)`!(${k} instanceof ${b.ValidationError})`, () => w.throw(k)), d(k), E || w.assign(y, !1);
      }), u.ok(y);
    }
    function $() {
      u.result((0, e.callValidateCode)(u, l, h), () => f(l), () => d(l));
    }
    function d(y) {
      const k = (0, r._)`${y}.errors`;
      w.assign(n.default.vErrors, (0, r._)`${n.default.vErrors} === null ? ${k} : ${n.default.vErrors}.concat(${k})`), w.assign(n.default.errors, (0, r._)`${n.default.vErrors}.length`);
    }
    function f(y) {
      var k;
      if (!b.opts.unevaluated)
        return;
      const I = (k = p == null ? void 0 : p.validate) === null || k === void 0 ? void 0 : k.evaluated;
      if (b.props !== !0)
        if (I && !I.dynamicProps)
          I.props !== void 0 && (b.props = i.mergeEvaluated.props(w, I.props, b.props));
        else {
          const N = w.var("props", (0, r._)`${y}.evaluated.props`);
          b.props = i.mergeEvaluated.props(w, N, b.props, r.Name);
        }
      if (b.items !== !0)
        if (I && !I.dynamicItems)
          I.items !== void 0 && (b.items = i.mergeEvaluated.items(w, I.items, b.items));
        else {
          const N = w.var("items", (0, r._)`${y}.evaluated.items`);
          b.items = i.mergeEvaluated.items(w, N, b.items, r.Name);
        }
    }
  }
  return Ct.callRef = c, Ct.default = a, Ct;
}
var Fo;
function Uy() {
  if (Fo) return rn;
  Fo = 1, Object.defineProperty(rn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ My(), e = /* @__PURE__ */ Dy(), r = [
    "$schema",
    "$id",
    "$defs",
    "$vocabulary",
    { keyword: "$comment" },
    "definitions",
    t.default,
    e.default
  ];
  return rn.default = r, rn;
}
var sn = {}, an = {}, Ho;
function Ly() {
  if (Ho) return an;
  Ho = 1, Object.defineProperty(an, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = t.operators, r = {
    maximum: { okStr: "<=", ok: e.LTE, fail: e.GT },
    minimum: { okStr: ">=", ok: e.GTE, fail: e.LT },
    exclusiveMaximum: { okStr: "<", ok: e.LT, fail: e.GTE },
    exclusiveMinimum: { okStr: ">", ok: e.GT, fail: e.LTE }
  }, n = {
    message: ({ keyword: i, schemaCode: a }) => (0, t.str)`must be ${r[i].okStr} ${a}`,
    params: ({ keyword: i, schemaCode: a }) => (0, t._)`{comparison: ${r[i].okStr}, limit: ${a}}`
  }, s = {
    keyword: Object.keys(r),
    type: "number",
    schemaType: "number",
    $data: !0,
    error: n,
    code(i) {
      const { keyword: a, data: o, schemaCode: c } = i;
      i.fail$data((0, t._)`${o} ${r[a].fail} ${c} || isNaN(${o})`);
    }
  };
  return an.default = s, an;
}
var on = {}, Vo;
function Zy() {
  if (Vo) return on;
  Vo = 1, Object.defineProperty(on, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), r = {
    keyword: "multipleOf",
    type: "number",
    schemaType: "number",
    $data: !0,
    error: {
      message: ({ schemaCode: n }) => (0, t.str)`must be multiple of ${n}`,
      params: ({ schemaCode: n }) => (0, t._)`{multipleOf: ${n}}`
    },
    code(n) {
      const { gen: s, data: i, schemaCode: a, it: o } = n, c = o.opts.multipleOfPrecision, u = s.let("res"), l = c ? (0, t._)`Math.abs(Math.round(${u}) - ${u}) > 1e-${c}` : (0, t._)`${u} !== parseInt(${u})`;
      n.fail$data((0, t._)`(${a} === 0 || (${u} = ${i}/${a}, ${l}))`);
    }
  };
  return on.default = r, on;
}
var cn = {}, un = {}, Ko;
function Fy() {
  if (Ko) return un;
  Ko = 1, Object.defineProperty(un, "__esModule", { value: !0 });
  function t(e) {
    const r = e.length;
    let n = 0, s = 0, i;
    for (; s < r; )
      n++, i = e.charCodeAt(s++), i >= 55296 && i <= 56319 && s < r && (i = e.charCodeAt(s), (i & 64512) === 56320 && s++);
    return n;
  }
  return un.default = t, t.code = 'require("ajv/dist/runtime/ucs2length").default', un;
}
var Go;
function Hy() {
  if (Go) return cn;
  Go = 1, Object.defineProperty(cn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ Fy(), s = {
    keyword: ["maxLength", "minLength"],
    type: "string",
    schemaType: "number",
    $data: !0,
    error: {
      message({ keyword: i, schemaCode: a }) {
        const o = i === "maxLength" ? "more" : "fewer";
        return (0, t.str)`must NOT have ${o} than ${a} characters`;
      },
      params: ({ schemaCode: i }) => (0, t._)`{limit: ${i}}`
    },
    code(i) {
      const { keyword: a, data: o, schemaCode: c, it: u } = i, l = a === "maxLength" ? t.operators.GT : t.operators.LT, p = u.opts.unicode === !1 ? (0, t._)`${o}.length` : (0, t._)`${(0, e.useFunc)(i.gen, r.default)}(${o})`;
      i.fail$data((0, t._)`${p} ${l} ${c}`);
    }
  };
  return cn.default = s, cn;
}
var ln = {}, Wo;
function Vy() {
  if (Wo) return ln;
  Wo = 1, Object.defineProperty(ln, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pt(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ oe(), s = {
    keyword: "pattern",
    type: "string",
    schemaType: "string",
    $data: !0,
    error: {
      message: ({ schemaCode: i }) => (0, r.str)`must match pattern "${i}"`,
      params: ({ schemaCode: i }) => (0, r._)`{pattern: ${i}}`
    },
    code(i) {
      const { gen: a, data: o, $data: c, schema: u, schemaCode: l, it: p } = i, v = p.opts.unicodeRegExp ? "u" : "";
      if (c) {
        const { regExp: w } = p.opts.code, b = w.code === "new RegExp" ? (0, r._)`new RegExp` : (0, e.useFunc)(a, w), E = a.let("valid");
        a.try(() => a.assign(E, (0, r._)`${b}(${l}, ${v}).test(${o})`), () => a.assign(E, !1)), i.fail$data((0, r._)`!${E}`);
      } else {
        const w = (0, t.usePattern)(i, u);
        i.fail$data((0, r._)`!${w}.test(${o})`);
      }
    }
  };
  return ln.default = s, ln;
}
var dn = {}, Jo;
function Ky() {
  if (Jo) return dn;
  Jo = 1, Object.defineProperty(dn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), r = {
    keyword: ["maxProperties", "minProperties"],
    type: "object",
    schemaType: "number",
    $data: !0,
    error: {
      message({ keyword: n, schemaCode: s }) {
        const i = n === "maxProperties" ? "more" : "fewer";
        return (0, t.str)`must NOT have ${i} than ${s} properties`;
      },
      params: ({ schemaCode: n }) => (0, t._)`{limit: ${n}}`
    },
    code(n) {
      const { keyword: s, data: i, schemaCode: a } = n, o = s === "maxProperties" ? t.operators.GT : t.operators.LT;
      n.fail$data((0, t._)`Object.keys(${i}).length ${o} ${a}`);
    }
  };
  return dn.default = r, dn;
}
var hn = {}, Bo;
function Gy() {
  if (Bo) return hn;
  Bo = 1, Object.defineProperty(hn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pt(), e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ pe(), s = {
    keyword: "required",
    type: "object",
    schemaType: "array",
    $data: !0,
    error: {
      message: ({ params: { missingProperty: i } }) => (0, e.str)`must have required property '${i}'`,
      params: ({ params: { missingProperty: i } }) => (0, e._)`{missingProperty: ${i}}`
    },
    code(i) {
      const { gen: a, schema: o, schemaCode: c, data: u, $data: l, it: p } = i, { opts: v } = p;
      if (!l && o.length === 0)
        return;
      const w = o.length >= v.loopRequired;
      if (p.allErrors ? b() : E(), v.strictRequired) {
        const h = i.parentSchema.properties, { definedProperties: g } = i.it;
        for (const $ of o)
          if ((h == null ? void 0 : h[$]) === void 0 && !g.has($)) {
            const d = p.schemaEnv.baseId + p.errSchemaPath, f = `required property "${$}" is not defined at "${d}" (strictRequired)`;
            (0, r.checkStrictMode)(p, f, p.opts.strictRequired);
          }
      }
      function b() {
        if (w || l)
          i.block$data(e.nil, _);
        else
          for (const h of o)
            (0, t.checkReportMissingProp)(i, h);
      }
      function E() {
        const h = a.let("missing");
        if (w || l) {
          const g = a.let("valid", !0);
          i.block$data(g, () => m(h, g)), i.ok(g);
        } else
          a.if((0, t.checkMissingProp)(i, o, h)), (0, t.reportMissingProp)(i, h), a.else();
      }
      function _() {
        a.forOf("prop", c, (h) => {
          i.setParams({ missingProperty: h }), a.if((0, t.noPropertyInData)(a, u, h, v.ownProperties), () => i.error());
        });
      }
      function m(h, g) {
        i.setParams({ missingProperty: h }), a.forOf(h, c, () => {
          a.assign(g, (0, t.propertyInData)(a, u, h, v.ownProperties)), a.if((0, e.not)(g), () => {
            i.error(), a.break();
          });
        }, e.nil);
      }
    }
  };
  return hn.default = s, hn;
}
var fn = {}, Qo;
function Wy() {
  if (Qo) return fn;
  Qo = 1, Object.defineProperty(fn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), r = {
    keyword: ["maxItems", "minItems"],
    type: "array",
    schemaType: "number",
    $data: !0,
    error: {
      message({ keyword: n, schemaCode: s }) {
        const i = n === "maxItems" ? "more" : "fewer";
        return (0, t.str)`must NOT have ${i} than ${s} items`;
      },
      params: ({ schemaCode: n }) => (0, t._)`{limit: ${n}}`
    },
    code(n) {
      const { keyword: s, data: i, schemaCode: a } = n, o = s === "maxItems" ? t.operators.GT : t.operators.LT;
      n.fail$data((0, t._)`${i}.length ${o} ${a}`);
    }
  };
  return fn.default = r, fn;
}
var pn = {}, mn = {}, Yo;
function oa() {
  if (Yo) return mn;
  Yo = 1, Object.defineProperty(mn, "__esModule", { value: !0 });
  const t = hl();
  return t.code = 'require("ajv/dist/runtime/equal").default', mn.default = t, mn;
}
var Xo;
function Jy() {
  if (Xo) return pn;
  Xo = 1, Object.defineProperty(pn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ is(), e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ pe(), n = /* @__PURE__ */ oa(), i = {
    keyword: "uniqueItems",
    type: "array",
    schemaType: "boolean",
    $data: !0,
    error: {
      message: ({ params: { i: a, j: o } }) => (0, e.str)`must NOT have duplicate items (items ## ${o} and ${a} are identical)`,
      params: ({ params: { i: a, j: o } }) => (0, e._)`{i: ${a}, j: ${o}}`
    },
    code(a) {
      const { gen: o, data: c, $data: u, schema: l, parentSchema: p, schemaCode: v, it: w } = a;
      if (!u && !l)
        return;
      const b = o.let("valid"), E = p.items ? (0, t.getSchemaTypes)(p.items) : [];
      a.block$data(b, _, (0, e._)`${v} === false`), a.ok(b);
      function _() {
        const $ = o.let("i", (0, e._)`${c}.length`), d = o.let("j");
        a.setParams({ i: $, j: d }), o.assign(b, !0), o.if((0, e._)`${$} > 1`, () => (m() ? h : g)($, d));
      }
      function m() {
        return E.length > 0 && !E.some(($) => $ === "object" || $ === "array");
      }
      function h($, d) {
        const f = o.name("item"), y = (0, t.checkDataTypes)(E, f, w.opts.strictNumbers, t.DataType.Wrong), k = o.const("indices", (0, e._)`{}`);
        o.for((0, e._)`;${$}--;`, () => {
          o.let(f, (0, e._)`${c}[${$}]`), o.if(y, (0, e._)`continue`), E.length > 1 && o.if((0, e._)`typeof ${f} == "string"`, (0, e._)`${f} += "_"`), o.if((0, e._)`typeof ${k}[${f}] == "number"`, () => {
            o.assign(d, (0, e._)`${k}[${f}]`), a.error(), o.assign(b, !1).break();
          }).code((0, e._)`${k}[${f}] = ${$}`);
        });
      }
      function g($, d) {
        const f = (0, r.useFunc)(o, n.default), y = o.name("outer");
        o.label(y).for((0, e._)`;${$}--;`, () => o.for((0, e._)`${d} = ${$}; ${d}--;`, () => o.if((0, e._)`${f}(${c}[${$}], ${c}[${d}])`, () => {
          a.error(), o.assign(b, !1).break(y);
        })));
      }
    }
  };
  return pn.default = i, pn;
}
var gn = {}, ec;
function By() {
  if (ec) return gn;
  ec = 1, Object.defineProperty(gn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ oa(), s = {
    keyword: "const",
    $data: !0,
    error: {
      message: "must be equal to constant",
      params: ({ schemaCode: i }) => (0, t._)`{allowedValue: ${i}}`
    },
    code(i) {
      const { gen: a, data: o, $data: c, schemaCode: u, schema: l } = i;
      c || l && typeof l == "object" ? i.fail$data((0, t._)`!${(0, e.useFunc)(a, r.default)}(${o}, ${u})`) : i.fail((0, t._)`${l} !== ${o}`);
    }
  };
  return gn.default = s, gn;
}
var _n = {}, tc;
function Qy() {
  if (tc) return _n;
  tc = 1, Object.defineProperty(_n, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ oa(), s = {
    keyword: "enum",
    schemaType: "array",
    $data: !0,
    error: {
      message: "must be equal to one of the allowed values",
      params: ({ schemaCode: i }) => (0, t._)`{allowedValues: ${i}}`
    },
    code(i) {
      const { gen: a, data: o, $data: c, schema: u, schemaCode: l, it: p } = i;
      if (!c && u.length === 0)
        throw new Error("enum must have non-empty array");
      const v = u.length >= p.opts.loopEnum;
      let w;
      const b = () => w ?? (w = (0, e.useFunc)(a, r.default));
      let E;
      if (v || c)
        E = a.let("valid"), i.block$data(E, _);
      else {
        if (!Array.isArray(u))
          throw new Error("ajv implementation error");
        const h = a.const("vSchema", l);
        E = (0, t.or)(...u.map((g, $) => m(h, $)));
      }
      i.pass(E);
      function _() {
        a.assign(E, !1), a.forOf("v", l, (h) => a.if((0, t._)`${b()}(${o}, ${h})`, () => a.assign(E, !0).break()));
      }
      function m(h, g) {
        const $ = u[g];
        return typeof $ == "object" && $ !== null ? (0, t._)`${b()}(${o}, ${h}[${g}])` : (0, t._)`${o} === ${$}`;
      }
    }
  };
  return _n.default = s, _n;
}
var rc;
function Yy() {
  if (rc) return sn;
  rc = 1, Object.defineProperty(sn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ Ly(), e = /* @__PURE__ */ Zy(), r = /* @__PURE__ */ Hy(), n = /* @__PURE__ */ Vy(), s = /* @__PURE__ */ Ky(), i = /* @__PURE__ */ Gy(), a = /* @__PURE__ */ Wy(), o = /* @__PURE__ */ Jy(), c = /* @__PURE__ */ By(), u = /* @__PURE__ */ Qy(), l = [
    // number
    t.default,
    e.default,
    // string
    r.default,
    n.default,
    // object
    s.default,
    i.default,
    // array
    a.default,
    o.default,
    // any
    { keyword: "type", schemaType: ["string", "array"] },
    { keyword: "nullable", schemaType: "boolean" },
    c.default,
    u.default
  ];
  return sn.default = l, sn;
}
var yn = {}, Xt = {}, nc;
function pl() {
  if (nc) return Xt;
  nc = 1, Object.defineProperty(Xt, "__esModule", { value: !0 }), Xt.validateAdditionalItems = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), n = {
    keyword: "additionalItems",
    type: "array",
    schemaType: ["boolean", "object"],
    before: "uniqueItems",
    error: {
      message: ({ params: { len: i } }) => (0, t.str)`must NOT have more than ${i} items`,
      params: ({ params: { len: i } }) => (0, t._)`{limit: ${i}}`
    },
    code(i) {
      const { parentSchema: a, it: o } = i, { items: c } = a;
      if (!Array.isArray(c)) {
        (0, e.checkStrictMode)(o, '"additionalItems" is ignored when "items" is not an array of schemas');
        return;
      }
      s(i, c);
    }
  };
  function s(i, a) {
    const { gen: o, schema: c, data: u, keyword: l, it: p } = i;
    p.items = !0;
    const v = o.const("len", (0, t._)`${u}.length`);
    if (c === !1)
      i.setParams({ len: a.length }), i.pass((0, t._)`${v} <= ${a.length}`);
    else if (typeof c == "object" && !(0, e.alwaysValidSchema)(p, c)) {
      const b = o.var("valid", (0, t._)`${v} <= ${a.length}`);
      o.if((0, t.not)(b), () => w(b)), i.ok(b);
    }
    function w(b) {
      o.forRange("i", a.length, v, (E) => {
        i.subschema({ keyword: l, dataProp: E, dataPropType: e.Type.Num }, b), p.allErrors || o.if((0, t.not)(b), () => o.break());
      });
    }
  }
  return Xt.validateAdditionalItems = s, Xt.default = n, Xt;
}
var vn = {}, er = {}, sc;
function ml() {
  if (sc) return er;
  sc = 1, Object.defineProperty(er, "__esModule", { value: !0 }), er.validateTuple = void 0;
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ pt(), n = {
    keyword: "items",
    type: "array",
    schemaType: ["object", "array", "boolean"],
    before: "uniqueItems",
    code(i) {
      const { schema: a, it: o } = i;
      if (Array.isArray(a))
        return s(i, "additionalItems", a);
      o.items = !0, !(0, e.alwaysValidSchema)(o, a) && i.ok((0, r.validateArray)(i));
    }
  };
  function s(i, a, o = i.schema) {
    const { gen: c, parentSchema: u, data: l, keyword: p, it: v } = i;
    E(u), v.opts.unevaluated && o.length && v.items !== !0 && (v.items = e.mergeEvaluated.items(c, o.length, v.items));
    const w = c.name("valid"), b = c.const("len", (0, t._)`${l}.length`);
    o.forEach((_, m) => {
      (0, e.alwaysValidSchema)(v, _) || (c.if((0, t._)`${b} > ${m}`, () => i.subschema({
        keyword: p,
        schemaProp: m,
        dataProp: m
      }, w)), i.ok(w));
    });
    function E(_) {
      const { opts: m, errSchemaPath: h } = v, g = o.length, $ = g === _.minItems && (g === _.maxItems || _[a] === !1);
      if (m.strictTuples && !$) {
        const d = `"${p}" is ${g}-tuple, but minItems or maxItems/${a} are not specified or different at path "${h}"`;
        (0, e.checkStrictMode)(v, d, m.strictTuples);
      }
    }
  }
  return er.validateTuple = s, er.default = n, er;
}
var ic;
function Xy() {
  if (ic) return vn;
  ic = 1, Object.defineProperty(vn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ ml(), e = {
    keyword: "prefixItems",
    type: "array",
    schemaType: ["array"],
    before: "uniqueItems",
    code: (r) => (0, t.validateTuple)(r, "items")
  };
  return vn.default = e, vn;
}
var wn = {}, ac;
function ev() {
  if (ac) return wn;
  ac = 1, Object.defineProperty(wn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), r = /* @__PURE__ */ pt(), n = /* @__PURE__ */ pl(), i = {
    keyword: "items",
    type: "array",
    schemaType: ["object", "boolean"],
    before: "uniqueItems",
    error: {
      message: ({ params: { len: a } }) => (0, t.str)`must NOT have more than ${a} items`,
      params: ({ params: { len: a } }) => (0, t._)`{limit: ${a}}`
    },
    code(a) {
      const { schema: o, parentSchema: c, it: u } = a, { prefixItems: l } = c;
      u.items = !0, !(0, e.alwaysValidSchema)(u, o) && (l ? (0, n.validateAdditionalItems)(a, l) : a.ok((0, r.validateArray)(a)));
    }
  };
  return wn.default = i, wn;
}
var bn = {}, oc;
function tv() {
  if (oc) return bn;
  oc = 1, Object.defineProperty(bn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), n = {
    keyword: "contains",
    type: "array",
    schemaType: ["object", "boolean"],
    before: "uniqueItems",
    trackErrors: !0,
    error: {
      message: ({ params: { min: s, max: i } }) => i === void 0 ? (0, t.str)`must contain at least ${s} valid item(s)` : (0, t.str)`must contain at least ${s} and no more than ${i} valid item(s)`,
      params: ({ params: { min: s, max: i } }) => i === void 0 ? (0, t._)`{minContains: ${s}}` : (0, t._)`{minContains: ${s}, maxContains: ${i}}`
    },
    code(s) {
      const { gen: i, schema: a, parentSchema: o, data: c, it: u } = s;
      let l, p;
      const { minContains: v, maxContains: w } = o;
      u.opts.next ? (l = v === void 0 ? 1 : v, p = w) : l = 1;
      const b = i.const("len", (0, t._)`${c}.length`);
      if (s.setParams({ min: l, max: p }), p === void 0 && l === 0) {
        (0, e.checkStrictMode)(u, '"minContains" == 0 without "maxContains": "contains" keyword ignored');
        return;
      }
      if (p !== void 0 && l > p) {
        (0, e.checkStrictMode)(u, '"minContains" > "maxContains" is always invalid'), s.fail();
        return;
      }
      if ((0, e.alwaysValidSchema)(u, a)) {
        let g = (0, t._)`${b} >= ${l}`;
        p !== void 0 && (g = (0, t._)`${g} && ${b} <= ${p}`), s.pass(g);
        return;
      }
      u.items = !0;
      const E = i.name("valid");
      p === void 0 && l === 1 ? m(E, () => i.if(E, () => i.break())) : l === 0 ? (i.let(E, !0), p !== void 0 && i.if((0, t._)`${c}.length > 0`, _)) : (i.let(E, !1), _()), s.result(E, () => s.reset());
      function _() {
        const g = i.name("_valid"), $ = i.let("count", 0);
        m(g, () => i.if(g, () => h($)));
      }
      function m(g, $) {
        i.forRange("i", 0, b, (d) => {
          s.subschema({
            keyword: "contains",
            dataProp: d,
            dataPropType: e.Type.Num,
            compositeRule: !0
          }, g), $();
        });
      }
      function h(g) {
        i.code((0, t._)`${g}++`), p === void 0 ? i.if((0, t._)`${g} >= ${l}`, () => i.assign(E, !0).break()) : (i.if((0, t._)`${g} > ${p}`, () => i.assign(E, !1).break()), l === 1 ? i.assign(E, !0) : i.if((0, t._)`${g} >= ${l}`, () => i.assign(E, !0)));
      }
    }
  };
  return bn.default = n, bn;
}
var Fs = {}, cc;
function rv() {
  return cc || (cc = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.validateSchemaDeps = t.validatePropertyDeps = t.error = void 0;
    const e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ pe(), n = /* @__PURE__ */ pt();
    t.error = {
      message: ({ params: { property: c, depsCount: u, deps: l } }) => {
        const p = u === 1 ? "property" : "properties";
        return (0, e.str)`must have ${p} ${l} when property ${c} is present`;
      },
      params: ({ params: { property: c, depsCount: u, deps: l, missingProperty: p } }) => (0, e._)`{property: ${c},
    missingProperty: ${p},
    depsCount: ${u},
    deps: ${l}}`
      // TODO change to reference
    };
    const s = {
      keyword: "dependencies",
      type: "object",
      schemaType: "object",
      error: t.error,
      code(c) {
        const [u, l] = i(c);
        a(c, u), o(c, l);
      }
    };
    function i({ schema: c }) {
      const u = {}, l = {};
      for (const p in c) {
        if (p === "__proto__")
          continue;
        const v = Array.isArray(c[p]) ? u : l;
        v[p] = c[p];
      }
      return [u, l];
    }
    function a(c, u = c.schema) {
      const { gen: l, data: p, it: v } = c;
      if (Object.keys(u).length === 0)
        return;
      const w = l.let("missing");
      for (const b in u) {
        const E = u[b];
        if (E.length === 0)
          continue;
        const _ = (0, n.propertyInData)(l, p, b, v.opts.ownProperties);
        c.setParams({
          property: b,
          depsCount: E.length,
          deps: E.join(", ")
        }), v.allErrors ? l.if(_, () => {
          for (const m of E)
            (0, n.checkReportMissingProp)(c, m);
        }) : (l.if((0, e._)`${_} && (${(0, n.checkMissingProp)(c, E, w)})`), (0, n.reportMissingProp)(c, w), l.else());
      }
    }
    t.validatePropertyDeps = a;
    function o(c, u = c.schema) {
      const { gen: l, data: p, keyword: v, it: w } = c, b = l.name("valid");
      for (const E in u)
        (0, r.alwaysValidSchema)(w, u[E]) || (l.if(
          (0, n.propertyInData)(l, p, E, w.opts.ownProperties),
          () => {
            const _ = c.subschema({ keyword: v, schemaProp: E }, b);
            c.mergeValidEvaluated(_, b);
          },
          () => l.var(b, !0)
          // TODO var
        ), c.ok(b));
    }
    t.validateSchemaDeps = o, t.default = s;
  })(Fs)), Fs;
}
var Sn = {}, uc;
function nv() {
  if (uc) return Sn;
  uc = 1, Object.defineProperty(Sn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), n = {
    keyword: "propertyNames",
    type: "object",
    schemaType: ["object", "boolean"],
    error: {
      message: "property name must be valid",
      params: ({ params: s }) => (0, t._)`{propertyName: ${s.propertyName}}`
    },
    code(s) {
      const { gen: i, schema: a, data: o, it: c } = s;
      if ((0, e.alwaysValidSchema)(c, a))
        return;
      const u = i.name("valid");
      i.forIn("key", o, (l) => {
        s.setParams({ propertyName: l }), s.subschema({
          keyword: "propertyNames",
          data: l,
          dataTypes: ["string"],
          propertyName: l,
          compositeRule: !0
        }, u), i.if((0, t.not)(u), () => {
          s.error(!0), c.allErrors || i.break();
        });
      }), s.ok(u);
    }
  };
  return Sn.default = n, Sn;
}
var kn = {}, lc;
function gl() {
  if (lc) return kn;
  lc = 1, Object.defineProperty(kn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pt(), e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ Dt(), n = /* @__PURE__ */ pe(), i = {
    keyword: "additionalProperties",
    type: ["object"],
    schemaType: ["boolean", "object"],
    allowUndefined: !0,
    trackErrors: !0,
    error: {
      message: "must NOT have additional properties",
      params: ({ params: a }) => (0, e._)`{additionalProperty: ${a.additionalProperty}}`
    },
    code(a) {
      const { gen: o, schema: c, parentSchema: u, data: l, errsCount: p, it: v } = a;
      if (!p)
        throw new Error("ajv implementation error");
      const { allErrors: w, opts: b } = v;
      if (v.props = !0, b.removeAdditional !== "all" && (0, n.alwaysValidSchema)(v, c))
        return;
      const E = (0, t.allSchemaProperties)(u.properties), _ = (0, t.allSchemaProperties)(u.patternProperties);
      m(), a.ok((0, e._)`${p} === ${r.default.errors}`);
      function m() {
        o.forIn("key", l, (f) => {
          !E.length && !_.length ? $(f) : o.if(h(f), () => $(f));
        });
      }
      function h(f) {
        let y;
        if (E.length > 8) {
          const k = (0, n.schemaRefOrVal)(v, u.properties, "properties");
          y = (0, t.isOwnProperty)(o, k, f);
        } else E.length ? y = (0, e.or)(...E.map((k) => (0, e._)`${f} === ${k}`)) : y = e.nil;
        return _.length && (y = (0, e.or)(y, ..._.map((k) => (0, e._)`${(0, t.usePattern)(a, k)}.test(${f})`))), (0, e.not)(y);
      }
      function g(f) {
        o.code((0, e._)`delete ${l}[${f}]`);
      }
      function $(f) {
        if (b.removeAdditional === "all" || b.removeAdditional && c === !1) {
          g(f);
          return;
        }
        if (c === !1) {
          a.setParams({ additionalProperty: f }), a.error(), w || o.break();
          return;
        }
        if (typeof c == "object" && !(0, n.alwaysValidSchema)(v, c)) {
          const y = o.name("valid");
          b.removeAdditional === "failing" ? (d(f, y, !1), o.if((0, e.not)(y), () => {
            a.reset(), g(f);
          })) : (d(f, y), w || o.if((0, e.not)(y), () => o.break()));
        }
      }
      function d(f, y, k) {
        const I = {
          keyword: "additionalProperties",
          dataProp: f,
          dataPropType: n.Type.Str
        };
        k === !1 && Object.assign(I, {
          compositeRule: !0,
          createErrors: !1,
          allErrors: !1
        }), a.subschema(I, y);
      }
    }
  };
  return kn.default = i, kn;
}
var $n = {}, dc;
function sv() {
  if (dc) return $n;
  dc = 1, Object.defineProperty($n, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ ms(), e = /* @__PURE__ */ pt(), r = /* @__PURE__ */ pe(), n = /* @__PURE__ */ gl(), s = {
    keyword: "properties",
    type: "object",
    schemaType: "object",
    code(i) {
      const { gen: a, schema: o, parentSchema: c, data: u, it: l } = i;
      l.opts.removeAdditional === "all" && c.additionalProperties === void 0 && n.default.code(new t.KeywordCxt(l, n.default, "additionalProperties"));
      const p = (0, e.allSchemaProperties)(o);
      for (const _ of p)
        l.definedProperties.add(_);
      l.opts.unevaluated && p.length && l.props !== !0 && (l.props = r.mergeEvaluated.props(a, (0, r.toHash)(p), l.props));
      const v = p.filter((_) => !(0, r.alwaysValidSchema)(l, o[_]));
      if (v.length === 0)
        return;
      const w = a.name("valid");
      for (const _ of v)
        b(_) ? E(_) : (a.if((0, e.propertyInData)(a, u, _, l.opts.ownProperties)), E(_), l.allErrors || a.else().var(w, !0), a.endIf()), i.it.definedProperties.add(_), i.ok(w);
      function b(_) {
        return l.opts.useDefaults && !l.compositeRule && o[_].default !== void 0;
      }
      function E(_) {
        i.subschema({
          keyword: "properties",
          schemaProp: _,
          dataProp: _
        }, w);
      }
    }
  };
  return $n.default = s, $n;
}
var En = {}, hc;
function iv() {
  if (hc) return En;
  hc = 1, Object.defineProperty(En, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pt(), e = /* @__PURE__ */ oe(), r = /* @__PURE__ */ pe(), n = /* @__PURE__ */ pe(), s = {
    keyword: "patternProperties",
    type: "object",
    schemaType: "object",
    code(i) {
      const { gen: a, schema: o, data: c, parentSchema: u, it: l } = i, { opts: p } = l, v = (0, t.allSchemaProperties)(o), w = v.filter(($) => (0, r.alwaysValidSchema)(l, o[$]));
      if (v.length === 0 || w.length === v.length && (!l.opts.unevaluated || l.props === !0))
        return;
      const b = p.strictSchema && !p.allowMatchingProperties && u.properties, E = a.name("valid");
      l.props !== !0 && !(l.props instanceof e.Name) && (l.props = (0, n.evaluatedPropsToName)(a, l.props));
      const { props: _ } = l;
      m();
      function m() {
        for (const $ of v)
          b && h($), l.allErrors ? g($) : (a.var(E, !0), g($), a.if(E));
      }
      function h($) {
        for (const d in b)
          new RegExp($).test(d) && (0, r.checkStrictMode)(l, `property ${d} matches pattern ${$} (use allowMatchingProperties)`);
      }
      function g($) {
        a.forIn("key", c, (d) => {
          a.if((0, e._)`${(0, t.usePattern)(i, $)}.test(${d})`, () => {
            const f = w.includes($);
            f || i.subschema({
              keyword: "patternProperties",
              schemaProp: $,
              dataProp: d,
              dataPropType: n.Type.Str
            }, E), l.opts.unevaluated && _ !== !0 ? a.assign((0, e._)`${_}[${d}]`, !0) : !f && !l.allErrors && a.if((0, e.not)(E), () => a.break());
          });
        });
      }
    }
  };
  return En.default = s, En;
}
var Tn = {}, fc;
function av() {
  if (fc) return Tn;
  fc = 1, Object.defineProperty(Tn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pe(), e = {
    keyword: "not",
    schemaType: ["object", "boolean"],
    trackErrors: !0,
    code(r) {
      const { gen: n, schema: s, it: i } = r;
      if ((0, t.alwaysValidSchema)(i, s)) {
        r.fail();
        return;
      }
      const a = n.name("valid");
      r.subschema({
        keyword: "not",
        compositeRule: !0,
        createErrors: !1,
        allErrors: !1
      }, a), r.failResult(a, () => r.reset(), () => r.error());
    },
    error: { message: "must NOT be valid" }
  };
  return Tn.default = e, Tn;
}
var Rn = {}, pc;
function ov() {
  if (pc) return Rn;
  pc = 1, Object.defineProperty(Rn, "__esModule", { value: !0 });
  const e = {
    keyword: "anyOf",
    schemaType: "array",
    trackErrors: !0,
    code: (/* @__PURE__ */ pt()).validateUnion,
    error: { message: "must match a schema in anyOf" }
  };
  return Rn.default = e, Rn;
}
var In = {}, mc;
function cv() {
  if (mc) return In;
  mc = 1, Object.defineProperty(In, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), n = {
    keyword: "oneOf",
    schemaType: "array",
    trackErrors: !0,
    error: {
      message: "must match exactly one schema in oneOf",
      params: ({ params: s }) => (0, t._)`{passingSchemas: ${s.passing}}`
    },
    code(s) {
      const { gen: i, schema: a, parentSchema: o, it: c } = s;
      if (!Array.isArray(a))
        throw new Error("ajv implementation error");
      if (c.opts.discriminator && o.discriminator)
        return;
      const u = a, l = i.let("valid", !1), p = i.let("passing", null), v = i.name("_valid");
      s.setParams({ passing: p }), i.block(w), s.result(l, () => s.reset(), () => s.error(!0));
      function w() {
        u.forEach((b, E) => {
          let _;
          (0, e.alwaysValidSchema)(c, b) ? i.var(v, !0) : _ = s.subschema({
            keyword: "oneOf",
            schemaProp: E,
            compositeRule: !0
          }, v), E > 0 && i.if((0, t._)`${v} && ${l}`).assign(l, !1).assign(p, (0, t._)`[${p}, ${E}]`).else(), i.if(v, () => {
            i.assign(l, !0), i.assign(p, E), _ && s.mergeEvaluated(_, t.Name);
          });
        });
      }
    }
  };
  return In.default = n, In;
}
var Pn = {}, gc;
function uv() {
  if (gc) return Pn;
  gc = 1, Object.defineProperty(Pn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pe(), e = {
    keyword: "allOf",
    schemaType: "array",
    code(r) {
      const { gen: n, schema: s, it: i } = r;
      if (!Array.isArray(s))
        throw new Error("ajv implementation error");
      const a = n.name("valid");
      s.forEach((o, c) => {
        if ((0, t.alwaysValidSchema)(i, o))
          return;
        const u = r.subschema({ keyword: "allOf", schemaProp: c }, a);
        r.ok(a), r.mergeEvaluated(u);
      });
    }
  };
  return Pn.default = e, Pn;
}
var On = {}, _c;
function lv() {
  if (_c) return On;
  _c = 1, Object.defineProperty(On, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ pe(), n = {
    keyword: "if",
    schemaType: ["object", "boolean"],
    trackErrors: !0,
    error: {
      message: ({ params: i }) => (0, t.str)`must match "${i.ifClause}" schema`,
      params: ({ params: i }) => (0, t._)`{failingKeyword: ${i.ifClause}}`
    },
    code(i) {
      const { gen: a, parentSchema: o, it: c } = i;
      o.then === void 0 && o.else === void 0 && (0, e.checkStrictMode)(c, '"if" without "then" and "else" is ignored');
      const u = s(c, "then"), l = s(c, "else");
      if (!u && !l)
        return;
      const p = a.let("valid", !0), v = a.name("_valid");
      if (w(), i.reset(), u && l) {
        const E = a.let("ifClause");
        i.setParams({ ifClause: E }), a.if(v, b("then", E), b("else", E));
      } else u ? a.if(v, b("then")) : a.if((0, t.not)(v), b("else"));
      i.pass(p, () => i.error(!0));
      function w() {
        const E = i.subschema({
          keyword: "if",
          compositeRule: !0,
          createErrors: !1,
          allErrors: !1
        }, v);
        i.mergeEvaluated(E);
      }
      function b(E, _) {
        return () => {
          const m = i.subschema({ keyword: E }, v);
          a.assign(p, v), i.mergeValidEvaluated(m, p), _ ? a.assign(_, (0, t._)`${E}`) : i.setParams({ ifClause: E });
        };
      }
    }
  };
  function s(i, a) {
    const o = i.schema[a];
    return o !== void 0 && !(0, e.alwaysValidSchema)(i, o);
  }
  return On.default = n, On;
}
var Cn = {}, yc;
function dv() {
  if (yc) return Cn;
  yc = 1, Object.defineProperty(Cn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pe(), e = {
    keyword: ["then", "else"],
    schemaType: ["object", "boolean"],
    code({ keyword: r, parentSchema: n, it: s }) {
      n.if === void 0 && (0, t.checkStrictMode)(s, `"${r}" without "if" is ignored`);
    }
  };
  return Cn.default = e, Cn;
}
var vc;
function hv() {
  if (vc) return yn;
  vc = 1, Object.defineProperty(yn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ pl(), e = /* @__PURE__ */ Xy(), r = /* @__PURE__ */ ml(), n = /* @__PURE__ */ ev(), s = /* @__PURE__ */ tv(), i = /* @__PURE__ */ rv(), a = /* @__PURE__ */ nv(), o = /* @__PURE__ */ gl(), c = /* @__PURE__ */ sv(), u = /* @__PURE__ */ iv(), l = /* @__PURE__ */ av(), p = /* @__PURE__ */ ov(), v = /* @__PURE__ */ cv(), w = /* @__PURE__ */ uv(), b = /* @__PURE__ */ lv(), E = /* @__PURE__ */ dv();
  function _(m = !1) {
    const h = [
      // any
      l.default,
      p.default,
      v.default,
      w.default,
      b.default,
      E.default,
      // object
      a.default,
      o.default,
      i.default,
      c.default,
      u.default
    ];
    return m ? h.push(e.default, n.default) : h.push(t.default, r.default), h.push(s.default), h;
  }
  return yn.default = _, yn;
}
var An = {}, Nn = {}, wc;
function fv() {
  if (wc) return Nn;
  wc = 1, Object.defineProperty(Nn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), r = {
    keyword: "format",
    type: ["number", "string"],
    schemaType: "string",
    $data: !0,
    error: {
      message: ({ schemaCode: n }) => (0, t.str)`must match format "${n}"`,
      params: ({ schemaCode: n }) => (0, t._)`{format: ${n}}`
    },
    code(n, s) {
      const { gen: i, data: a, $data: o, schema: c, schemaCode: u, it: l } = n, { opts: p, errSchemaPath: v, schemaEnv: w, self: b } = l;
      if (!p.validateFormats)
        return;
      o ? E() : _();
      function E() {
        const m = i.scopeValue("formats", {
          ref: b.formats,
          code: p.code.formats
        }), h = i.const("fDef", (0, t._)`${m}[${u}]`), g = i.let("fType"), $ = i.let("format");
        i.if((0, t._)`typeof ${h} == "object" && !(${h} instanceof RegExp)`, () => i.assign(g, (0, t._)`${h}.type || "string"`).assign($, (0, t._)`${h}.validate`), () => i.assign(g, (0, t._)`"string"`).assign($, h)), n.fail$data((0, t.or)(d(), f()));
        function d() {
          return p.strictSchema === !1 ? t.nil : (0, t._)`${u} && !${$}`;
        }
        function f() {
          const y = w.$async ? (0, t._)`(${h}.async ? await ${$}(${a}) : ${$}(${a}))` : (0, t._)`${$}(${a})`, k = (0, t._)`(typeof ${$} == "function" ? ${y} : ${$}.test(${a}))`;
          return (0, t._)`${$} && ${$} !== true && ${g} === ${s} && !${k}`;
        }
      }
      function _() {
        const m = b.formats[c];
        if (!m) {
          d();
          return;
        }
        if (m === !0)
          return;
        const [h, g, $] = f(m);
        h === s && n.pass(y());
        function d() {
          if (p.strictSchema === !1) {
            b.logger.warn(k());
            return;
          }
          throw new Error(k());
          function k() {
            return `unknown format "${c}" ignored in schema at path "${v}"`;
          }
        }
        function f(k) {
          const I = k instanceof RegExp ? (0, t.regexpCode)(k) : p.code.formats ? (0, t._)`${p.code.formats}${(0, t.getProperty)(c)}` : void 0, N = i.scopeValue("formats", { key: c, ref: k, code: I });
          return typeof k == "object" && !(k instanceof RegExp) ? [k.type || "string", k.validate, (0, t._)`${N}.validate`] : ["string", k, N];
        }
        function y() {
          if (typeof m == "object" && !(m instanceof RegExp) && m.async) {
            if (!w.$async)
              throw new Error("async format in sync schema");
            return (0, t._)`await ${$}(${a})`;
          }
          return typeof g == "function" ? (0, t._)`${$}(${a})` : (0, t._)`${$}.test(${a})`;
        }
      }
    }
  };
  return Nn.default = r, Nn;
}
var bc;
function pv() {
  if (bc) return An;
  bc = 1, Object.defineProperty(An, "__esModule", { value: !0 });
  const e = [(/* @__PURE__ */ fv()).default];
  return An.default = e, An;
}
var Ht = {}, Sc;
function mv() {
  return Sc || (Sc = 1, Object.defineProperty(Ht, "__esModule", { value: !0 }), Ht.contentVocabulary = Ht.metadataVocabulary = void 0, Ht.metadataVocabulary = [
    "title",
    "description",
    "default",
    "deprecated",
    "readOnly",
    "writeOnly",
    "examples"
  ], Ht.contentVocabulary = [
    "contentMediaType",
    "contentEncoding",
    "contentSchema"
  ]), Ht;
}
var kc;
function gv() {
  if (kc) return tn;
  kc = 1, Object.defineProperty(tn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ Uy(), e = /* @__PURE__ */ Yy(), r = /* @__PURE__ */ hv(), n = /* @__PURE__ */ pv(), s = /* @__PURE__ */ mv(), i = [
    t.default,
    e.default,
    (0, r.default)(),
    n.default,
    s.metadataVocabulary,
    s.contentVocabulary
  ];
  return tn.default = i, tn;
}
var xn = {}, wr = {}, $c;
function _v() {
  if ($c) return wr;
  $c = 1, Object.defineProperty(wr, "__esModule", { value: !0 }), wr.DiscrError = void 0;
  var t;
  return (function(e) {
    e.Tag = "tag", e.Mapping = "mapping";
  })(t || (wr.DiscrError = t = {})), wr;
}
var Ec;
function yv() {
  if (Ec) return xn;
  Ec = 1, Object.defineProperty(xn, "__esModule", { value: !0 });
  const t = /* @__PURE__ */ oe(), e = /* @__PURE__ */ _v(), r = /* @__PURE__ */ aa(), n = /* @__PURE__ */ gs(), s = /* @__PURE__ */ pe(), a = {
    keyword: "discriminator",
    type: "object",
    schemaType: "object",
    error: {
      message: ({ params: { discrError: o, tagName: c } }) => o === e.DiscrError.Tag ? `tag "${c}" must be string` : `value of tag "${c}" must be in oneOf`,
      params: ({ params: { discrError: o, tag: c, tagName: u } }) => (0, t._)`{error: ${o}, tag: ${u}, tagValue: ${c}}`
    },
    code(o) {
      const { gen: c, data: u, schema: l, parentSchema: p, it: v } = o, { oneOf: w } = p;
      if (!v.opts.discriminator)
        throw new Error("discriminator: requires discriminator option");
      const b = l.propertyName;
      if (typeof b != "string")
        throw new Error("discriminator: requires propertyName");
      if (l.mapping)
        throw new Error("discriminator: mapping is not supported");
      if (!w)
        throw new Error("discriminator: requires oneOf keyword");
      const E = c.let("valid", !1), _ = c.const("tag", (0, t._)`${u}${(0, t.getProperty)(b)}`);
      c.if((0, t._)`typeof ${_} == "string"`, () => m(), () => o.error(!1, { discrError: e.DiscrError.Tag, tag: _, tagName: b })), o.ok(E);
      function m() {
        const $ = g();
        c.if(!1);
        for (const d in $)
          c.elseIf((0, t._)`${_} === ${d}`), c.assign(E, h($[d]));
        c.else(), o.error(!1, { discrError: e.DiscrError.Mapping, tag: _, tagName: b }), c.endIf();
      }
      function h($) {
        const d = c.name("valid"), f = o.subschema({ keyword: "oneOf", schemaProp: $ }, d);
        return o.mergeEvaluated(f, t.Name), d;
      }
      function g() {
        var $;
        const d = {}, f = k(p);
        let y = !0;
        for (let R = 0; R < w.length; R++) {
          let j = w[R];
          if (j != null && j.$ref && !(0, s.schemaHasRulesButRef)(j, v.self.RULES)) {
            const H = j.$ref;
            if (j = r.resolveRef.call(v.self, v.schemaEnv.root, v.baseId, H), j instanceof r.SchemaEnv && (j = j.schema), j === void 0)
              throw new n.default(v.opts.uriResolver, v.baseId, H);
          }
          const F = ($ = j == null ? void 0 : j.properties) === null || $ === void 0 ? void 0 : $[b];
          if (typeof F != "object")
            throw new Error(`discriminator: oneOf subschemas (or referenced schemas) must have "properties/${b}"`);
          y = y && (f || k(j)), I(F, R);
        }
        if (!y)
          throw new Error(`discriminator: "${b}" must be required`);
        return d;
        function k({ required: R }) {
          return Array.isArray(R) && R.includes(b);
        }
        function I(R, j) {
          if (R.const)
            N(R.const, j);
          else if (R.enum)
            for (const F of R.enum)
              N(F, j);
          else
            throw new Error(`discriminator: "properties/${b}" must have "const" or "enum"`);
        }
        function N(R, j) {
          if (typeof R != "string" || R in d)
            throw new Error(`discriminator: "${b}" values must be unique strings`);
          d[R] = j;
        }
      }
    }
  };
  return xn.default = a, xn;
}
const vv = "http://json-schema.org/draft-07/schema#", wv = "http://json-schema.org/draft-07/schema#", bv = "Core schema meta-schema", Sv = { schemaArray: { type: "array", minItems: 1, items: { $ref: "#" } }, nonNegativeInteger: { type: "integer", minimum: 0 }, nonNegativeIntegerDefault0: { allOf: [{ $ref: "#/definitions/nonNegativeInteger" }, { default: 0 }] }, simpleTypes: { enum: ["array", "boolean", "integer", "null", "number", "object", "string"] }, stringArray: { type: "array", items: { type: "string" }, uniqueItems: !0, default: [] } }, kv = ["object", "boolean"], $v = { $id: { type: "string", format: "uri-reference" }, $schema: { type: "string", format: "uri" }, $ref: { type: "string", format: "uri-reference" }, $comment: { type: "string" }, title: { type: "string" }, description: { type: "string" }, default: !0, readOnly: { type: "boolean", default: !1 }, examples: { type: "array", items: !0 }, multipleOf: { type: "number", exclusiveMinimum: 0 }, maximum: { type: "number" }, exclusiveMaximum: { type: "number" }, minimum: { type: "number" }, exclusiveMinimum: { type: "number" }, maxLength: { $ref: "#/definitions/nonNegativeInteger" }, minLength: { $ref: "#/definitions/nonNegativeIntegerDefault0" }, pattern: { type: "string", format: "regex" }, additionalItems: { $ref: "#" }, items: { anyOf: [{ $ref: "#" }, { $ref: "#/definitions/schemaArray" }], default: !0 }, maxItems: { $ref: "#/definitions/nonNegativeInteger" }, minItems: { $ref: "#/definitions/nonNegativeIntegerDefault0" }, uniqueItems: { type: "boolean", default: !1 }, contains: { $ref: "#" }, maxProperties: { $ref: "#/definitions/nonNegativeInteger" }, minProperties: { $ref: "#/definitions/nonNegativeIntegerDefault0" }, required: { $ref: "#/definitions/stringArray" }, additionalProperties: { $ref: "#" }, definitions: { type: "object", additionalProperties: { $ref: "#" }, default: {} }, properties: { type: "object", additionalProperties: { $ref: "#" }, default: {} }, patternProperties: { type: "object", additionalProperties: { $ref: "#" }, propertyNames: { format: "regex" }, default: {} }, dependencies: { type: "object", additionalProperties: { anyOf: [{ $ref: "#" }, { $ref: "#/definitions/stringArray" }] } }, propertyNames: { $ref: "#" }, const: !0, enum: { type: "array", items: !0, minItems: 1, uniqueItems: !0 }, type: { anyOf: [{ $ref: "#/definitions/simpleTypes" }, { type: "array", items: { $ref: "#/definitions/simpleTypes" }, minItems: 1, uniqueItems: !0 }] }, format: { type: "string" }, contentMediaType: { type: "string" }, contentEncoding: { type: "string" }, if: { $ref: "#" }, then: { $ref: "#" }, else: { $ref: "#" }, allOf: { $ref: "#/definitions/schemaArray" }, anyOf: { $ref: "#/definitions/schemaArray" }, oneOf: { $ref: "#/definitions/schemaArray" }, not: { $ref: "#" } }, Ev = {
  $schema: vv,
  $id: wv,
  title: bv,
  definitions: Sv,
  type: kv,
  properties: $v,
  default: !0
};
var Tc;
function _l() {
  return Tc || (Tc = 1, (function(t, e) {
    Object.defineProperty(e, "__esModule", { value: !0 }), e.MissingRefError = e.ValidationError = e.CodeGen = e.Name = e.nil = e.stringify = e.str = e._ = e.KeywordCxt = e.Ajv = void 0;
    const r = /* @__PURE__ */ qy(), n = /* @__PURE__ */ gv(), s = /* @__PURE__ */ yv(), i = Ev, a = ["/properties"], o = "http://json-schema.org/draft-07/schema";
    class c extends r.default {
      _addVocabularies() {
        super._addVocabularies(), n.default.forEach((b) => this.addVocabulary(b)), this.opts.discriminator && this.addKeyword(s.default);
      }
      _addDefaultMetaSchema() {
        if (super._addDefaultMetaSchema(), !this.opts.meta)
          return;
        const b = this.opts.$data ? this.$dataMetaSchema(i, a) : i;
        this.addMetaSchema(b, o, !1), this.refs["http://json-schema.org/schema"] = o;
      }
      defaultMeta() {
        return this.opts.defaultMeta = super.defaultMeta() || (this.getSchema(o) ? o : void 0);
      }
    }
    e.Ajv = c, t.exports = e = c, t.exports.Ajv = c, Object.defineProperty(e, "__esModule", { value: !0 }), e.default = c;
    var u = /* @__PURE__ */ ms();
    Object.defineProperty(e, "KeywordCxt", { enumerable: !0, get: function() {
      return u.KeywordCxt;
    } });
    var l = /* @__PURE__ */ oe();
    Object.defineProperty(e, "_", { enumerable: !0, get: function() {
      return l._;
    } }), Object.defineProperty(e, "str", { enumerable: !0, get: function() {
      return l.str;
    } }), Object.defineProperty(e, "stringify", { enumerable: !0, get: function() {
      return l.stringify;
    } }), Object.defineProperty(e, "nil", { enumerable: !0, get: function() {
      return l.nil;
    } }), Object.defineProperty(e, "Name", { enumerable: !0, get: function() {
      return l.Name;
    } }), Object.defineProperty(e, "CodeGen", { enumerable: !0, get: function() {
      return l.CodeGen;
    } });
    var p = /* @__PURE__ */ ia();
    Object.defineProperty(e, "ValidationError", { enumerable: !0, get: function() {
      return p.default;
    } });
    var v = /* @__PURE__ */ gs();
    Object.defineProperty(e, "MissingRefError", { enumerable: !0, get: function() {
      return v.default;
    } });
  })(Br, Br.exports)), Br.exports;
}
var Tv = /* @__PURE__ */ _l();
const Rv = /* @__PURE__ */ ul(Tv);
var jn = { exports: {} }, Hs = {}, Rc;
function Iv() {
  return Rc || (Rc = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.formatNames = t.fastFormats = t.fullFormats = void 0;
    function e(R, j) {
      return { validate: R, compare: j };
    }
    t.fullFormats = {
      // date: http://tools.ietf.org/html/rfc3339#section-5.6
      date: e(i, a),
      // date-time: http://tools.ietf.org/html/rfc3339#section-5.6
      time: e(c(!0), u),
      "date-time": e(v(!0), w),
      "iso-time": e(c(), l),
      "iso-date-time": e(v(), b),
      // duration: https://tools.ietf.org/html/rfc3339#appendix-A
      duration: /^P(?!$)((\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?|(\d+W)?)$/,
      uri: m,
      "uri-reference": /^(?:[a-z][a-z0-9+\-.]*:)?(?:\/?\/(?:(?:[a-z0-9\-._~!$&'()*+,;=:]|%[0-9a-f]{2})*@)?(?:\[(?:(?:(?:(?:[0-9a-f]{1,4}:){6}|::(?:[0-9a-f]{1,4}:){5}|(?:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){4}|(?:(?:[0-9a-f]{1,4}:){0,1}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){3}|(?:(?:[0-9a-f]{1,4}:){0,2}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){2}|(?:(?:[0-9a-f]{1,4}:){0,3}[0-9a-f]{1,4})?::[0-9a-f]{1,4}:|(?:(?:[0-9a-f]{1,4}:){0,4}[0-9a-f]{1,4})?::)(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?))|(?:(?:[0-9a-f]{1,4}:){0,5}[0-9a-f]{1,4})?::[0-9a-f]{1,4}|(?:(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{1,4})?::)|[Vv][0-9a-f]+\.[a-z0-9\-._~!$&'()*+,;=:]+)\]|(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)|(?:[a-z0-9\-._~!$&'"()*+,;=]|%[0-9a-f]{2})*)(?::\d*)?(?:\/(?:[a-z0-9\-._~!$&'"()*+,;=:@]|%[0-9a-f]{2})*)*|\/(?:(?:[a-z0-9\-._~!$&'"()*+,;=:@]|%[0-9a-f]{2})+(?:\/(?:[a-z0-9\-._~!$&'"()*+,;=:@]|%[0-9a-f]{2})*)*)?|(?:[a-z0-9\-._~!$&'"()*+,;=:@]|%[0-9a-f]{2})+(?:\/(?:[a-z0-9\-._~!$&'"()*+,;=:@]|%[0-9a-f]{2})*)*)?(?:\?(?:[a-z0-9\-._~!$&'"()*+,;=:@/?]|%[0-9a-f]{2})*)?(?:#(?:[a-z0-9\-._~!$&'"()*+,;=:@/?]|%[0-9a-f]{2})*)?$/i,
      // uri-template: https://tools.ietf.org/html/rfc6570
      "uri-template": /^(?:(?:[^\x00-\x20"'<>%\\^`{|}]|%[0-9a-f]{2})|\{[+#./;?&=,!@|]?(?:[a-z0-9_]|%[0-9a-f]{2})+(?::[1-9][0-9]{0,3}|\*)?(?:,(?:[a-z0-9_]|%[0-9a-f]{2})+(?::[1-9][0-9]{0,3}|\*)?)*\})*$/i,
      // For the source: https://gist.github.com/dperini/729294
      // For test cases: https://mathiasbynens.be/demo/url-regex
      url: /^(?:https?|ftp):\/\/(?:\S+(?::\S*)?@)?(?:(?!(?:10|127)(?:\.\d{1,3}){3})(?!(?:169\.254|192\.168)(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z0-9\u{00a1}-\u{ffff}]+-)*[a-z0-9\u{00a1}-\u{ffff}]+)(?:\.(?:[a-z0-9\u{00a1}-\u{ffff}]+-)*[a-z0-9\u{00a1}-\u{ffff}]+)*(?:\.(?:[a-z\u{00a1}-\u{ffff}]{2,})))(?::\d{2,5})?(?:\/[^\s]*)?$/iu,
      email: /^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i,
      hostname: /^(?=.{1,253}\.?$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[-0-9a-z]{0,61}[0-9a-z])?)*\.?$/i,
      // optimized https://www.safaribooksonline.com/library/view/regular-expressions-cookbook/9780596802837/ch07s16.html
      ipv4: /^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/,
      ipv6: /^((([0-9a-f]{1,4}:){7}([0-9a-f]{1,4}|:))|(([0-9a-f]{1,4}:){6}(:[0-9a-f]{1,4}|((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9a-f]{1,4}:){5}(((:[0-9a-f]{1,4}){1,2})|:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9a-f]{1,4}:){4}(((:[0-9a-f]{1,4}){1,3})|((:[0-9a-f]{1,4})?:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9a-f]{1,4}:){3}(((:[0-9a-f]{1,4}){1,4})|((:[0-9a-f]{1,4}){0,2}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9a-f]{1,4}:){2}(((:[0-9a-f]{1,4}){1,5})|((:[0-9a-f]{1,4}){0,3}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9a-f]{1,4}:){1}(((:[0-9a-f]{1,4}){1,6})|((:[0-9a-f]{1,4}){0,4}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(:(((:[0-9a-f]{1,4}){1,7})|((:[0-9a-f]{1,4}){0,5}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:)))$/i,
      regex: N,
      // uuid: http://tools.ietf.org/html/rfc4122
      uuid: /^(?:urn:uuid:)?[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i,
      // JSON-pointer: https://tools.ietf.org/html/rfc6901
      // uri fragment: https://tools.ietf.org/html/rfc3986#appendix-A
      "json-pointer": /^(?:\/(?:[^~/]|~0|~1)*)*$/,
      "json-pointer-uri-fragment": /^#(?:\/(?:[a-z0-9_\-.!$&'()*+,;:=@]|%[0-9a-f]{2}|~0|~1)*)*$/i,
      // relative JSON-pointer: http://tools.ietf.org/html/draft-luff-relative-json-pointer-00
      "relative-json-pointer": /^(?:0|[1-9][0-9]*)(?:#|(?:\/(?:[^~/]|~0|~1)*)*)$/,
      // the following formats are used by the openapi specification: https://spec.openapis.org/oas/v3.0.0#data-types
      // byte: https://github.com/miguelmota/is-base64
      byte: g,
      // signed 32 bit integer
      int32: { type: "number", validate: f },
      // signed 64 bit integer
      int64: { type: "number", validate: y },
      // C-type float
      float: { type: "number", validate: k },
      // C-type double
      double: { type: "number", validate: k },
      // hint to the UI to hide input strings
      password: !0,
      // unchecked string payload
      binary: !0
    }, t.fastFormats = {
      ...t.fullFormats,
      date: e(/^\d\d\d\d-[0-1]\d-[0-3]\d$/, a),
      time: e(/^(?:[0-2]\d:[0-5]\d:[0-5]\d|23:59:60)(?:\.\d+)?(?:z|[+-]\d\d(?::?\d\d)?)$/i, u),
      "date-time": e(/^\d\d\d\d-[0-1]\d-[0-3]\dt(?:[0-2]\d:[0-5]\d:[0-5]\d|23:59:60)(?:\.\d+)?(?:z|[+-]\d\d(?::?\d\d)?)$/i, w),
      "iso-time": e(/^(?:[0-2]\d:[0-5]\d:[0-5]\d|23:59:60)(?:\.\d+)?(?:z|[+-]\d\d(?::?\d\d)?)?$/i, l),
      "iso-date-time": e(/^\d\d\d\d-[0-1]\d-[0-3]\d[t\s](?:[0-2]\d:[0-5]\d:[0-5]\d|23:59:60)(?:\.\d+)?(?:z|[+-]\d\d(?::?\d\d)?)?$/i, b),
      // uri: https://github.com/mafintosh/is-my-json-valid/blob/master/formats.js
      uri: /^(?:[a-z][a-z0-9+\-.]*:)(?:\/?\/)?[^\s]*$/i,
      "uri-reference": /^(?:(?:[a-z][a-z0-9+\-.]*:)?\/?\/)?(?:[^\\\s#][^\s#]*)?(?:#[^\\\s]*)?$/i,
      // email (sources from jsen validator):
      // http://stackoverflow.com/questions/201323/using-a-regular-expression-to-validate-an-email-address#answer-8829363
      // http://www.w3.org/TR/html5/forms.html#valid-e-mail-address (search for 'wilful violation')
      email: /^[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i
    }, t.formatNames = Object.keys(t.fullFormats);
    function r(R) {
      return R % 4 === 0 && (R % 100 !== 0 || R % 400 === 0);
    }
    const n = /^(\d\d\d\d)-(\d\d)-(\d\d)$/, s = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    function i(R) {
      const j = n.exec(R);
      if (!j)
        return !1;
      const F = +j[1], H = +j[2], se = +j[3];
      return H >= 1 && H <= 12 && se >= 1 && se <= (H === 2 && r(F) ? 29 : s[H]);
    }
    function a(R, j) {
      if (R && j)
        return R > j ? 1 : R < j ? -1 : 0;
    }
    const o = /^(\d\d):(\d\d):(\d\d(?:\.\d+)?)(z|([+-])(\d\d)(?::?(\d\d))?)?$/i;
    function c(R) {
      return function(F) {
        const H = o.exec(F);
        if (!H)
          return !1;
        const se = +H[1], ke = +H[2], je = +H[3], fe = H[4], Je = H[5] === "-" ? -1 : 1, L = +(H[6] || 0), O = +(H[7] || 0);
        if (L > 23 || O > 59 || R && !fe)
          return !1;
        if (se <= 23 && ke <= 59 && je < 60)
          return !0;
        const D = ke - O * Je, A = se - L * Je - (D < 0 ? 1 : 0);
        return (A === 23 || A === -1) && (D === 59 || D === -1) && je < 61;
      };
    }
    function u(R, j) {
      if (!(R && j))
        return;
      const F = (/* @__PURE__ */ new Date("2020-01-01T" + R)).valueOf(), H = (/* @__PURE__ */ new Date("2020-01-01T" + j)).valueOf();
      if (F && H)
        return F - H;
    }
    function l(R, j) {
      if (!(R && j))
        return;
      const F = o.exec(R), H = o.exec(j);
      if (F && H)
        return R = F[1] + F[2] + F[3], j = H[1] + H[2] + H[3], R > j ? 1 : R < j ? -1 : 0;
    }
    const p = /t|\s/i;
    function v(R) {
      const j = c(R);
      return function(H) {
        const se = H.split(p);
        return se.length === 2 && i(se[0]) && j(se[1]);
      };
    }
    function w(R, j) {
      if (!(R && j))
        return;
      const F = new Date(R).valueOf(), H = new Date(j).valueOf();
      if (F && H)
        return F - H;
    }
    function b(R, j) {
      if (!(R && j))
        return;
      const [F, H] = R.split(p), [se, ke] = j.split(p), je = a(F, se);
      if (je !== void 0)
        return je || u(H, ke);
    }
    const E = /\/|:/, _ = /^(?:[a-z][a-z0-9+\-.]*:)(?:\/?\/(?:(?:[a-z0-9\-._~!$&'()*+,;=:]|%[0-9a-f]{2})*@)?(?:\[(?:(?:(?:(?:[0-9a-f]{1,4}:){6}|::(?:[0-9a-f]{1,4}:){5}|(?:[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){4}|(?:(?:[0-9a-f]{1,4}:){0,1}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){3}|(?:(?:[0-9a-f]{1,4}:){0,2}[0-9a-f]{1,4})?::(?:[0-9a-f]{1,4}:){2}|(?:(?:[0-9a-f]{1,4}:){0,3}[0-9a-f]{1,4})?::[0-9a-f]{1,4}:|(?:(?:[0-9a-f]{1,4}:){0,4}[0-9a-f]{1,4})?::)(?:[0-9a-f]{1,4}:[0-9a-f]{1,4}|(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?))|(?:(?:[0-9a-f]{1,4}:){0,5}[0-9a-f]{1,4})?::[0-9a-f]{1,4}|(?:(?:[0-9a-f]{1,4}:){0,6}[0-9a-f]{1,4})?::)|[Vv][0-9a-f]+\.[a-z0-9\-._~!$&'()*+,;=:]+)\]|(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)|(?:[a-z0-9\-._~!$&'()*+,;=]|%[0-9a-f]{2})*)(?::\d*)?(?:\/(?:[a-z0-9\-._~!$&'()*+,;=:@]|%[0-9a-f]{2})*)*|\/(?:(?:[a-z0-9\-._~!$&'()*+,;=:@]|%[0-9a-f]{2})+(?:\/(?:[a-z0-9\-._~!$&'()*+,;=:@]|%[0-9a-f]{2})*)*)?|(?:[a-z0-9\-._~!$&'()*+,;=:@]|%[0-9a-f]{2})+(?:\/(?:[a-z0-9\-._~!$&'()*+,;=:@]|%[0-9a-f]{2})*)*)(?:\?(?:[a-z0-9\-._~!$&'()*+,;=:@/?]|%[0-9a-f]{2})*)?(?:#(?:[a-z0-9\-._~!$&'()*+,;=:@/?]|%[0-9a-f]{2})*)?$/i;
    function m(R) {
      return E.test(R) && _.test(R);
    }
    const h = /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/gm;
    function g(R) {
      return h.lastIndex = 0, h.test(R);
    }
    const $ = -2147483648, d = 2 ** 31 - 1;
    function f(R) {
      return Number.isInteger(R) && R <= d && R >= $;
    }
    function y(R) {
      return Number.isInteger(R);
    }
    function k() {
      return !0;
    }
    const I = /[^\\]\\Z/;
    function N(R) {
      if (I.test(R))
        return !1;
      try {
        return new RegExp(R), !0;
      } catch {
        return !1;
      }
    }
  })(Hs)), Hs;
}
var Vs = {}, Ic;
function Pv() {
  return Ic || (Ic = 1, (function(t) {
    Object.defineProperty(t, "__esModule", { value: !0 }), t.formatLimitDefinition = void 0;
    const e = /* @__PURE__ */ _l(), r = /* @__PURE__ */ oe(), n = r.operators, s = {
      formatMaximum: { okStr: "<=", ok: n.LTE, fail: n.GT },
      formatMinimum: { okStr: ">=", ok: n.GTE, fail: n.LT },
      formatExclusiveMaximum: { okStr: "<", ok: n.LT, fail: n.GTE },
      formatExclusiveMinimum: { okStr: ">", ok: n.GT, fail: n.LTE }
    }, i = {
      message: ({ keyword: o, schemaCode: c }) => (0, r.str)`should be ${s[o].okStr} ${c}`,
      params: ({ keyword: o, schemaCode: c }) => (0, r._)`{comparison: ${s[o].okStr}, limit: ${c}}`
    };
    t.formatLimitDefinition = {
      keyword: Object.keys(s),
      type: "string",
      schemaType: "string",
      $data: !0,
      error: i,
      code(o) {
        const { gen: c, data: u, schemaCode: l, keyword: p, it: v } = o, { opts: w, self: b } = v;
        if (!w.validateFormats)
          return;
        const E = new e.KeywordCxt(v, b.RULES.all.format.definition, "format");
        E.$data ? _() : m();
        function _() {
          const g = c.scopeValue("formats", {
            ref: b.formats,
            code: w.code.formats
          }), $ = c.const("fmt", (0, r._)`${g}[${E.schemaCode}]`);
          o.fail$data((0, r.or)((0, r._)`typeof ${$} != "object"`, (0, r._)`${$} instanceof RegExp`, (0, r._)`typeof ${$}.compare != "function"`, h($)));
        }
        function m() {
          const g = E.schema, $ = b.formats[g];
          if (!$ || $ === !0)
            return;
          if (typeof $ != "object" || $ instanceof RegExp || typeof $.compare != "function")
            throw new Error(`"${p}": format "${g}" does not define "compare" function`);
          const d = c.scopeValue("formats", {
            key: g,
            ref: $,
            code: w.code.formats ? (0, r._)`${w.code.formats}${(0, r.getProperty)(g)}` : void 0
          });
          o.fail$data(h(d));
        }
        function h(g) {
          return (0, r._)`${g}.compare(${u}, ${l}) ${s[p].fail} 0`;
        }
      },
      dependencies: ["format"]
    };
    const a = (o) => (o.addKeyword(t.formatLimitDefinition), o);
    t.default = a;
  })(Vs)), Vs;
}
var Pc;
function Ov() {
  return Pc || (Pc = 1, (function(t, e) {
    Object.defineProperty(e, "__esModule", { value: !0 });
    const r = Iv(), n = Pv(), s = /* @__PURE__ */ oe(), i = new s.Name("fullFormats"), a = new s.Name("fastFormats"), o = (u, l = { keywords: !0 }) => {
      if (Array.isArray(l))
        return c(u, l, r.fullFormats, i), u;
      const [p, v] = l.mode === "fast" ? [r.fastFormats, a] : [r.fullFormats, i], w = l.formats || r.formatNames;
      return c(u, w, p, v), l.keywords && (0, n.default)(u), u;
    };
    o.get = (u, l = "full") => {
      const v = (l === "fast" ? r.fastFormats : r.fullFormats)[u];
      if (!v)
        throw new Error(`Unknown format "${u}"`);
      return v;
    };
    function c(u, l, p, v) {
      var w, b;
      (w = (b = u.opts.code).formats) !== null && w !== void 0 || (b.formats = (0, s._)`require("ajv-formats/dist/formats").${v}`);
      for (const E of l)
        u.addFormat(E, p[E]);
    }
    t.exports = e = o, Object.defineProperty(e, "__esModule", { value: !0 }), e.default = o;
  })(jn, jn.exports)), jn.exports;
}
var Cv = Ov();
const Av = /* @__PURE__ */ ul(Cv);
function Nv() {
  const t = new Rv({
    strict: !1,
    validateFormats: !0,
    validateSchema: !1,
    allErrors: !0
  });
  return Av(t), t;
}
class xv {
  /**
   * Create an AJV validator
   *
   * @param ajv - Optional pre-configured AJV instance. If not provided, a default instance will be created.
   *
   * @example
   * ```typescript
   * // Use default configuration (recommended for most cases)
   * import { AjvJsonSchemaValidator } from '@modelcontextprotocol/sdk/validation/ajv';
   * const validator = new AjvJsonSchemaValidator();
   *
   * // Or provide custom AJV instance for advanced configuration
   * import { Ajv } from 'ajv';
   * import addFormats from 'ajv-formats';
   *
   * const ajv = new Ajv({ validateFormats: true });
   * addFormats(ajv);
   * const validator = new AjvJsonSchemaValidator(ajv);
   * ```
   */
  constructor(e) {
    this._ajv = e ?? Nv();
  }
  /**
   * Create a validator for the given JSON Schema
   *
   * The validator is compiled once and can be reused multiple times.
   * If the schema has an $id, it will be cached by AJV automatically.
   *
   * @param schema - Standard JSON Schema object
   * @returns A validator function that validates input data
   */
  getValidator(e) {
    const r = "$id" in e && typeof e.$id == "string" ? this._ajv.getSchema(e.$id) ?? this._ajv.compile(e) : this._ajv.compile(e);
    return (n) => r(n) ? {
      valid: !0,
      data: n,
      errorMessage: void 0
    } : {
      valid: !1,
      data: void 0,
      errorMessage: this._ajv.errorsText(r.errors)
    };
  }
}
class jv {
  constructor(e) {
    this._server = e;
  }
  /**
   * Sends a request and returns an AsyncGenerator that yields response messages.
   * The generator is guaranteed to end with either a 'result' or 'error' message.
   *
   * This method provides streaming access to request processing, allowing you to
   * observe intermediate task status updates for task-augmented requests.
   *
   * @param request - The request to send
   * @param resultSchema - Zod schema for validating the result
   * @param options - Optional request options (timeout, signal, task creation params, etc.)
   * @returns AsyncGenerator that yields ResponseMessage objects
   *
   * @experimental
   */
  requestStream(e, r, n) {
    return this._server.requestStream(e, r, n);
  }
  /**
   * Sends a sampling request and returns an AsyncGenerator that yields response messages.
   * The generator is guaranteed to end with either a 'result' or 'error' message.
   *
   * For task-augmented requests, yields 'taskCreated' and 'taskStatus' messages
   * before the final result.
   *
   * @example
   * ```typescript
   * const stream = server.experimental.tasks.createMessageStream({
   *     messages: [{ role: 'user', content: { type: 'text', text: 'Hello' } }],
   *     maxTokens: 100
   * }, {
   *     onprogress: (progress) => {
   *         // Handle streaming tokens via progress notifications
   *         console.log('Progress:', progress.message);
   *     }
   * });
   *
   * for await (const message of stream) {
   *     switch (message.type) {
   *         case 'taskCreated':
   *             console.log('Task created:', message.task.taskId);
   *             break;
   *         case 'taskStatus':
   *             console.log('Task status:', message.task.status);
   *             break;
   *         case 'result':
   *             console.log('Final result:', message.result);
   *             break;
   *         case 'error':
   *             console.error('Error:', message.error);
   *             break;
   *     }
   * }
   * ```
   *
   * @param params - The sampling request parameters
   * @param options - Optional request options (timeout, signal, task creation params, onprogress, etc.)
   * @returns AsyncGenerator that yields ResponseMessage objects
   *
   * @experimental
   */
  createMessageStream(e, r) {
    var s;
    const n = this._server.getClientCapabilities();
    if ((e.tools || e.toolChoice) && !((s = n == null ? void 0 : n.sampling) != null && s.tools))
      throw new Error("Client does not support sampling tools capability.");
    if (e.messages.length > 0) {
      const i = e.messages[e.messages.length - 1], a = Array.isArray(i.content) ? i.content : [i.content], o = a.some((p) => p.type === "tool_result"), c = e.messages.length > 1 ? e.messages[e.messages.length - 2] : void 0, u = c ? Array.isArray(c.content) ? c.content : [c.content] : [], l = u.some((p) => p.type === "tool_use");
      if (o) {
        if (a.some((p) => p.type !== "tool_result"))
          throw new Error("The last message must contain only tool_result content if any is present");
        if (!l)
          throw new Error("tool_result blocks are not matching any tool_use from the previous message");
      }
      if (l) {
        const p = new Set(u.filter((w) => w.type === "tool_use").map((w) => w.id)), v = new Set(a.filter((w) => w.type === "tool_result").map((w) => w.toolUseId));
        if (p.size !== v.size || ![...p].every((w) => v.has(w)))
          throw new Error("ids of tool_result blocks and tool_use blocks from previous message do not match");
      }
    }
    return this.requestStream({
      method: "sampling/createMessage",
      params: e
    }, Yi, r);
  }
  /**
   * Sends an elicitation request and returns an AsyncGenerator that yields response messages.
   * The generator is guaranteed to end with either a 'result' or 'error' message.
   *
   * For task-augmented requests (especially URL-based elicitation), yields 'taskCreated'
   * and 'taskStatus' messages before the final result.
   *
   * @example
   * ```typescript
   * const stream = server.experimental.tasks.elicitInputStream({
   *     mode: 'url',
   *     message: 'Please authenticate',
   *     elicitationId: 'auth-123',
   *     url: 'https://example.com/auth'
   * }, {
   *     task: { ttl: 300000 } // Task-augmented for long-running auth flow
   * });
   *
   * for await (const message of stream) {
   *     switch (message.type) {
   *         case 'taskCreated':
   *             console.log('Task created:', message.task.taskId);
   *             break;
   *         case 'taskStatus':
   *             console.log('Task status:', message.task.status);
   *             break;
   *         case 'result':
   *             console.log('User action:', message.result.action);
   *             break;
   *         case 'error':
   *             console.error('Error:', message.error);
   *             break;
   *     }
   * }
   * ```
   *
   * @param params - The elicitation request parameters
   * @param options - Optional request options (timeout, signal, task creation params, etc.)
   * @returns AsyncGenerator that yields ResponseMessage objects
   *
   * @experimental
   */
  elicitInputStream(e, r) {
    var a, o;
    const n = this._server.getClientCapabilities(), s = e.mode ?? "form";
    switch (s) {
      case "url": {
        if (!((a = n == null ? void 0 : n.elicitation) != null && a.url))
          throw new Error("Client does not support url elicitation.");
        break;
      }
      case "form": {
        if (!((o = n == null ? void 0 : n.elicitation) != null && o.form))
          throw new Error("Client does not support form elicitation.");
        break;
      }
    }
    const i = s === "form" && e.mode === void 0 ? { ...e, mode: "form" } : e;
    return this.requestStream({
      method: "elicitation/create",
      params: i
    }, Vn, r);
  }
  /**
   * Gets the current status of a task.
   *
   * @param taskId - The task identifier
   * @param options - Optional request options
   * @returns The task status
   *
   * @experimental
   */
  async getTask(e, r) {
    return this._server.getTask({ taskId: e }, r);
  }
  /**
   * Retrieves the result of a completed task.
   *
   * @param taskId - The task identifier
   * @param resultSchema - Zod schema for validating the result
   * @param options - Optional request options
   * @returns The task result
   *
   * @experimental
   */
  async getTaskResult(e, r, n) {
    return this._server.getTaskResult({ taskId: e }, r, n);
  }
  /**
   * Lists tasks with optional pagination.
   *
   * @param cursor - Optional pagination cursor
   * @param options - Optional request options
   * @returns List of tasks with optional next cursor
   *
   * @experimental
   */
  async listTasks(e, r) {
    return this._server.listTasks(e ? { cursor: e } : void 0, r);
  }
  /**
   * Cancels a running task.
   *
   * @param taskId - The task identifier
   * @param options - Optional request options
   *
   * @experimental
   */
  async cancelTask(e, r) {
    return this._server.cancelTask({ taskId: e }, r);
  }
}
function zv(t, e, r) {
  var n;
  if (!t)
    throw new Error(`${r} does not support task creation (required for ${e})`);
  switch (e) {
    case "tools/call":
      if (!((n = t.tools) != null && n.call))
        throw new Error(`${r} does not support task creation for tools/call (required for ${e})`);
      break;
  }
}
function qv(t, e, r) {
  var n, s;
  if (!t)
    throw new Error(`${r} does not support task creation (required for ${e})`);
  switch (e) {
    case "sampling/createMessage":
      if (!((n = t.sampling) != null && n.createMessage))
        throw new Error(`${r} does not support task creation for sampling/createMessage (required for ${e})`);
      break;
    case "elicitation/create":
      if (!((s = t.elicitation) != null && s.create))
        throw new Error(`${r} does not support task creation for elicitation/create (required for ${e})`);
      break;
  }
}
class Mv extends wy {
  /**
   * Initializes this server with the given name and version information.
   */
  constructor(e, r) {
    super(r), this._serverInfo = e, this._loggingLevels = /* @__PURE__ */ new Map(), this.LOG_LEVEL_SEVERITY = new Map(Fn.options.map((n, s) => [n, s])), this.isMessageIgnored = (n, s) => {
      const i = this._loggingLevels.get(s);
      return i ? this.LOG_LEVEL_SEVERITY.get(n) < this.LOG_LEVEL_SEVERITY.get(i) : !1;
    }, this._capabilities = (r == null ? void 0 : r.capabilities) ?? {}, this._instructions = r == null ? void 0 : r.instructions, this._jsonSchemaValidator = (r == null ? void 0 : r.jsonSchemaValidator) ?? new xv(), this.setRequestHandler(wu, (n) => this._oninitialize(n)), this.setNotificationHandler(bu, () => {
      var n;
      return (n = this.oninitialized) == null ? void 0 : n.call(this);
    }), this._capabilities.logging && this.setRequestHandler(Ru, async (n, s) => {
      var c;
      const i = s.sessionId || ((c = s.requestInfo) == null ? void 0 : c.headers["mcp-session-id"]) || void 0, { level: a } = n.params, o = Fn.safeParse(a);
      return o.success && this._loggingLevels.set(i, o.data), {};
    });
  }
  /**
   * Access experimental features.
   *
   * WARNING: These APIs are experimental and may change without notice.
   *
   * @experimental
   */
  get experimental() {
    return this._experimental || (this._experimental = {
      tasks: new jv(this)
    }), this._experimental;
  }
  /**
   * Registers new capabilities. This can only be called before connecting to a transport.
   *
   * The new capabilities will be merged with any existing capabilities previously given (e.g., at initialization).
   */
  registerCapabilities(e) {
    if (this.transport)
      throw new Error("Cannot register capabilities after connecting to transport");
    this._capabilities = by(this._capabilities, e);
  }
  /**
   * Override request handler registration to enforce server-side validation for tools/call.
   */
  setRequestHandler(e, r) {
    var o;
    const n = Fr(e), s = n == null ? void 0 : n.method;
    if (!s)
      throw new Error("Schema is missing a method literal");
    let i;
    if (bt(s)) {
      const c = s, u = (o = c._zod) == null ? void 0 : o.def;
      i = (u == null ? void 0 : u.value) ?? c.value;
    } else {
      const c = s, u = c._def;
      i = (u == null ? void 0 : u.value) ?? c.value;
    }
    if (typeof i != "string")
      throw new Error("Schema method literal must be a string");
    if (i === "tools/call") {
      const c = async (u, l) => {
        const p = Rr(Zn, u);
        if (!p.success) {
          const E = p.error instanceof Error ? p.error.message : String(p.error);
          throw new X(re.InvalidParams, `Invalid tools/call request: ${E}`);
        }
        const { params: v } = p.data, w = await Promise.resolve(r(u, l));
        if (v.task) {
          const E = Rr(ds, w);
          if (!E.success) {
            const _ = E.error instanceof Error ? E.error.message : String(E.error);
            throw new X(re.InvalidParams, `Invalid task creation result: ${_}`);
          }
          return E.data;
        }
        const b = Rr(Qi, w);
        if (!b.success) {
          const E = b.error instanceof Error ? b.error.message : String(b.error);
          throw new X(re.InvalidParams, `Invalid tools/call result: ${E}`);
        }
        return b.data;
      };
      return super.setRequestHandler(e, c);
    }
    return super.setRequestHandler(e, r);
  }
  assertCapabilityForMethod(e) {
    var r, n, s;
    switch (e) {
      case "sampling/createMessage":
        if (!((r = this._clientCapabilities) != null && r.sampling))
          throw new Error(`Client does not support sampling (required for ${e})`);
        break;
      case "elicitation/create":
        if (!((n = this._clientCapabilities) != null && n.elicitation))
          throw new Error(`Client does not support elicitation (required for ${e})`);
        break;
      case "roots/list":
        if (!((s = this._clientCapabilities) != null && s.roots))
          throw new Error(`Client does not support listing roots (required for ${e})`);
        break;
    }
  }
  assertNotificationCapability(e) {
    var r, n;
    switch (e) {
      case "notifications/message":
        if (!this._capabilities.logging)
          throw new Error(`Server does not support logging (required for ${e})`);
        break;
      case "notifications/resources/updated":
      case "notifications/resources/list_changed":
        if (!this._capabilities.resources)
          throw new Error(`Server does not support notifying about resources (required for ${e})`);
        break;
      case "notifications/tools/list_changed":
        if (!this._capabilities.tools)
          throw new Error(`Server does not support notifying of tool list changes (required for ${e})`);
        break;
      case "notifications/prompts/list_changed":
        if (!this._capabilities.prompts)
          throw new Error(`Server does not support notifying of prompt list changes (required for ${e})`);
        break;
      case "notifications/elicitation/complete":
        if (!((n = (r = this._clientCapabilities) == null ? void 0 : r.elicitation) != null && n.url))
          throw new Error(`Client does not support URL elicitation (required for ${e})`);
        break;
    }
  }
  assertRequestHandlerCapability(e) {
    if (this._capabilities)
      switch (e) {
        case "completion/complete":
          if (!this._capabilities.completions)
            throw new Error(`Server does not support completions (required for ${e})`);
          break;
        case "logging/setLevel":
          if (!this._capabilities.logging)
            throw new Error(`Server does not support logging (required for ${e})`);
          break;
        case "prompts/get":
        case "prompts/list":
          if (!this._capabilities.prompts)
            throw new Error(`Server does not support prompts (required for ${e})`);
          break;
        case "resources/list":
        case "resources/templates/list":
        case "resources/read":
          if (!this._capabilities.resources)
            throw new Error(`Server does not support resources (required for ${e})`);
          break;
        case "tools/call":
        case "tools/list":
          if (!this._capabilities.tools)
            throw new Error(`Server does not support tools (required for ${e})`);
          break;
        case "tasks/get":
        case "tasks/list":
        case "tasks/result":
        case "tasks/cancel":
          if (!this._capabilities.tasks)
            throw new Error(`Server does not support tasks capability (required for ${e})`);
          break;
      }
  }
  assertTaskCapability(e) {
    var r, n;
    qv((n = (r = this._clientCapabilities) == null ? void 0 : r.tasks) == null ? void 0 : n.requests, e, "Client");
  }
  assertTaskHandlerCapability(e) {
    var r;
    this._capabilities && zv((r = this._capabilities.tasks) == null ? void 0 : r.requests, e, "Server");
  }
  async _oninitialize(e) {
    const r = e.params.protocolVersion;
    return this._clientCapabilities = e.params.capabilities, this._clientVersion = e.params.clientInfo, {
      protocolVersion: wp.includes(r) ? r : pu,
      capabilities: this.getCapabilities(),
      serverInfo: this._serverInfo,
      ...this._instructions && { instructions: this._instructions }
    };
  }
  /**
   * After initialization has completed, this will be populated with the client's reported capabilities.
   */
  getClientCapabilities() {
    return this._clientCapabilities;
  }
  /**
   * After initialization has completed, this will be populated with information about the client's name and version.
   */
  getClientVersion() {
    return this._clientVersion;
  }
  getCapabilities() {
    return this._capabilities;
  }
  async ping() {
    return this.request({ method: "ping" }, ji);
  }
  // Implementation
  async createMessage(e, r) {
    var n, s;
    if ((e.tools || e.toolChoice) && !((s = (n = this._clientCapabilities) == null ? void 0 : n.sampling) != null && s.tools))
      throw new Error("Client does not support sampling tools capability.");
    if (e.messages.length > 0) {
      const i = e.messages[e.messages.length - 1], a = Array.isArray(i.content) ? i.content : [i.content], o = a.some((p) => p.type === "tool_result"), c = e.messages.length > 1 ? e.messages[e.messages.length - 2] : void 0, u = c ? Array.isArray(c.content) ? c.content : [c.content] : [], l = u.some((p) => p.type === "tool_use");
      if (o) {
        if (a.some((p) => p.type !== "tool_result"))
          throw new Error("The last message must contain only tool_result content if any is present");
        if (!l)
          throw new Error("tool_result blocks are not matching any tool_use from the previous message");
      }
      if (l) {
        const p = new Set(u.filter((w) => w.type === "tool_use").map((w) => w.id)), v = new Set(a.filter((w) => w.type === "tool_result").map((w) => w.toolUseId));
        if (p.size !== v.size || ![...p].every((w) => v.has(w)))
          throw new Error("ids of tool_result blocks and tool_use blocks from previous message do not match");
      }
    }
    return e.tools ? this.request({ method: "sampling/createMessage", params: e }, Iu, r) : this.request({ method: "sampling/createMessage", params: e }, Yi, r);
  }
  /**
   * Creates an elicitation request for the given parameters.
   * For backwards compatibility, `mode` may be omitted for form requests and will default to `'form'`.
   * @param params The parameters for the elicitation request.
   * @param options Optional request options.
   * @returns The result of the elicitation request.
   */
  async elicitInput(e, r) {
    var s, i, a, o;
    switch (e.mode ?? "form") {
      case "url": {
        if (!((i = (s = this._clientCapabilities) == null ? void 0 : s.elicitation) != null && i.url))
          throw new Error("Client does not support url elicitation.");
        const c = e;
        return this.request({ method: "elicitation/create", params: c }, Vn, r);
      }
      case "form": {
        if (!((o = (a = this._clientCapabilities) == null ? void 0 : a.elicitation) != null && o.form))
          throw new Error("Client does not support form elicitation.");
        const c = e.mode === "form" ? e : { ...e, mode: "form" }, u = await this.request({ method: "elicitation/create", params: c }, Vn, r);
        if (u.action === "accept" && u.content && c.requestedSchema)
          try {
            const p = this._jsonSchemaValidator.getValidator(c.requestedSchema)(u.content);
            if (!p.valid)
              throw new X(re.InvalidParams, `Elicitation response content does not match requested schema: ${p.errorMessage}`);
          } catch (l) {
            throw l instanceof X ? l : new X(re.InternalError, `Error validating elicitation response: ${l instanceof Error ? l.message : String(l)}`);
          }
        return u;
      }
    }
  }
  /**
   * Creates a reusable callback that, when invoked, will send a `notifications/elicitation/complete`
   * notification for the specified elicitation ID.
   *
   * @param elicitationId The ID of the elicitation to mark as complete.
   * @param options Optional notification options. Useful when the completion notification should be related to a prior request.
   * @returns A function that emits the completion notification when awaited.
   */
  createElicitationCompletionNotifier(e, r) {
    var n, s;
    if (!((s = (n = this._clientCapabilities) == null ? void 0 : n.elicitation) != null && s.url))
      throw new Error("Client does not support URL elicitation (required for notifications/elicitation/complete)");
    return () => this.notification({
      method: "notifications/elicitation/complete",
      params: {
        elicitationId: e
      }
    }, r);
  }
  async listRoots(e, r) {
    return this.request({ method: "roots/list", params: e }, Pu, r);
  }
  /**
   * Sends a logging message to the client, if connected.
   * Note: You only need to send the parameters object, not the entire JSON RPC message
   * @see LoggingMessageNotification
   * @param params
   * @param sessionId optional for stateless and backward compatibility
   */
  async sendLoggingMessage(e, r) {
    if (this._capabilities.logging && !this.isMessageIgnored(e.level, r))
      return this.notification({ method: "notifications/message", params: e });
  }
  async sendResourceUpdated(e) {
    return this.notification({
      method: "notifications/resources/updated",
      params: e
    });
  }
  async sendResourceListChanged() {
    return this.notification({
      method: "notifications/resources/list_changed"
    });
  }
  async sendToolListChanged() {
    return this.notification({ method: "notifications/tools/list_changed" });
  }
  async sendPromptListChanged() {
    return this.notification({ method: "notifications/prompts/list_changed" });
  }
}
const yl = Symbol.for("mcp.completable");
function Oc(t) {
  return !!t && typeof t == "object" && yl in t;
}
function Dv(t) {
  const e = t[yl];
  return e == null ? void 0 : e.complete;
}
var Cc;
(function(t) {
  t.Completable = "McpCompletable";
})(Cc || (Cc = {}));
const Uv = /^[A-Za-z0-9._-]{1,128}$/;
function Lv(t) {
  const e = [];
  if (t.length === 0)
    return {
      isValid: !1,
      warnings: ["Tool name cannot be empty"]
    };
  if (t.length > 128)
    return {
      isValid: !1,
      warnings: [`Tool name exceeds maximum length of 128 characters (current: ${t.length})`]
    };
  if (t.includes(" ") && e.push("Tool name contains spaces, which may cause parsing issues"), t.includes(",") && e.push("Tool name contains commas, which may cause parsing issues"), (t.startsWith("-") || t.endsWith("-")) && e.push("Tool name starts or ends with a dash, which may cause parsing issues in some contexts"), (t.startsWith(".") || t.endsWith(".")) && e.push("Tool name starts or ends with a dot, which may cause parsing issues in some contexts"), !Uv.test(t)) {
    const r = t.split("").filter((n) => !/[A-Za-z0-9._-]/.test(n)).filter((n, s, i) => i.indexOf(n) === s);
    return e.push(`Tool name contains invalid characters: ${r.map((n) => `"${n}"`).join(", ")}`, "Allowed characters are: A-Z, a-z, 0-9, underscore (_), dash (-), and dot (.)"), {
      isValid: !1,
      warnings: e
    };
  }
  return {
    isValid: !0,
    warnings: e
  };
}
function Zv(t, e) {
  if (e.length > 0) {
    console.warn(`Tool name validation warning for "${t}":`);
    for (const r of e)
      console.warn(`  - ${r}`);
    console.warn("Tool registration will proceed, but this may cause compatibility issues."), console.warn("Consider updating the tool name to conform to the MCP tool naming standard."), console.warn("See SEP: Specify Format for Tool Names (https://github.com/modelcontextprotocol/modelcontextprotocol/issues/986) for more details.");
  }
}
function Ac(t) {
  const e = Lv(t);
  return Zv(t, e.warnings), e.isValid;
}
class Fv {
  constructor(e) {
    this._mcpServer = e;
  }
  registerToolTask(e, r, n) {
    const s = { taskSupport: "required", ...r.execution };
    if (s.taskSupport === "forbidden")
      throw new Error(`Cannot register task-based tool '${e}' with taskSupport 'forbidden'. Use registerTool() instead.`);
    return this._mcpServer._createRegisteredTool(e, r.title, r.description, r.inputSchema, r.outputSchema, r.annotations, s, r._meta, n);
  }
}
class Hv {
  constructor(e, r) {
    this._registeredResources = {}, this._registeredResourceTemplates = {}, this._registeredTools = {}, this._registeredPrompts = {}, this._toolHandlersInitialized = !1, this._completionHandlerInitialized = !1, this._resourceHandlersInitialized = !1, this._promptHandlersInitialized = !1, this.server = new Mv(e, r);
  }
  /**
   * Access experimental features.
   *
   * WARNING: These APIs are experimental and may change without notice.
   *
   * @experimental
   */
  get experimental() {
    return this._experimental || (this._experimental = {
      tasks: new Fv(this)
    }), this._experimental;
  }
  /**
   * Attaches to the given transport, starts it, and starts listening for messages.
   *
   * The `server` object assumes ownership of the Transport, replacing any callbacks that have already been set, and expects that it is the only user of the Transport instance going forward.
   */
  async connect(e) {
    return await this.server.connect(e);
  }
  /**
   * Closes the connection.
   */
  async close() {
    await this.server.close();
  }
  setToolRequestHandlers() {
    this._toolHandlersInitialized || (this.server.assertCanSetRequestHandler(At(ei)), this.server.assertCanSetRequestHandler(At(Zn)), this.server.registerCapabilities({
      tools: {
        listChanged: !0
      }
    }), this.server.setRequestHandler(ei, () => ({
      tools: Object.entries(this._registeredTools).filter(([, e]) => e.enabled).map(([e, r]) => {
        const n = {
          name: e,
          title: r.title,
          description: r.description,
          inputSchema: (() => {
            const s = _r(r.inputSchema);
            return s ? uo(s, {
              strictUnions: !0,
              pipeStrategy: "input"
            }) : Vv;
          })(),
          annotations: r.annotations,
          execution: r.execution,
          _meta: r._meta
        };
        if (r.outputSchema) {
          const s = _r(r.outputSchema);
          s && (n.outputSchema = uo(s, {
            strictUnions: !0,
            pipeStrategy: "output"
          }));
        }
        return n;
      })
    })), this.server.setRequestHandler(Zn, async (e, r) => {
      var n;
      try {
        const s = this._registeredTools[e.params.name];
        if (!s)
          throw new X(re.InvalidParams, `Tool ${e.params.name} not found`);
        if (!s.enabled)
          throw new X(re.InvalidParams, `Tool ${e.params.name} disabled`);
        const i = !!e.params.task, a = (n = s.execution) == null ? void 0 : n.taskSupport, o = "createTask" in s.handler;
        if ((a === "required" || a === "optional") && !o)
          throw new X(re.InternalError, `Tool ${e.params.name} has taskSupport '${a}' but was not registered with registerToolTask`);
        if (a === "required" && !i)
          throw new X(re.MethodNotFound, `Tool ${e.params.name} requires task augmentation (taskSupport: 'required')`);
        if (a === "optional" && !i && o)
          return await this.handleAutomaticTaskPolling(s, e, r);
        const c = await this.validateToolInput(s, e.params.arguments, e.params.name), u = await this.executeToolHandler(s, c, r);
        return i || await this.validateToolOutput(s, u, e.params.name), u;
      } catch (s) {
        if (s instanceof X && s.code === re.UrlElicitationRequired)
          throw s;
        return this.createToolError(s instanceof Error ? s.message : String(s));
      }
    }), this._toolHandlersInitialized = !0);
  }
  /**
   * Creates a tool error result.
   *
   * @param errorMessage - The error message.
   * @returns The tool error result.
   */
  createToolError(e) {
    return {
      content: [
        {
          type: "text",
          text: e
        }
      ],
      isError: !0
    };
  }
  /**
   * Validates tool input arguments against the tool's input schema.
   */
  async validateToolInput(e, r, n) {
    if (!e.inputSchema)
      return;
    const i = _r(e.inputSchema) ?? e.inputSchema, a = await Os(i, r);
    if (!a.success) {
      const o = "error" in a ? a.error : "Unknown error", c = Cs(o);
      throw new X(re.InvalidParams, `Input validation error: Invalid arguments for tool ${n}: ${c}`);
    }
    return a.data;
  }
  /**
   * Validates tool output against the tool's output schema.
   */
  async validateToolOutput(e, r, n) {
    if (!e.outputSchema || !("content" in r) || r.isError)
      return;
    if (!r.structuredContent)
      throw new X(re.InvalidParams, `Output validation error: Tool ${n} has an output schema but no structured content was provided`);
    const s = _r(e.outputSchema), i = await Os(s, r.structuredContent);
    if (!i.success) {
      const a = "error" in i ? i.error : "Unknown error", o = Cs(a);
      throw new X(re.InvalidParams, `Output validation error: Invalid structured content for tool ${n}: ${o}`);
    }
  }
  /**
   * Executes a tool handler (either regular or task-based).
   */
  async executeToolHandler(e, r, n) {
    const s = e.handler;
    if ("createTask" in s) {
      if (!n.taskStore)
        throw new Error("No task store provided.");
      const a = { ...n, taskStore: n.taskStore };
      if (e.inputSchema) {
        const o = s;
        return await Promise.resolve(o.createTask(r, a));
      } else {
        const o = s;
        return await Promise.resolve(o.createTask(a));
      }
    }
    if (e.inputSchema) {
      const a = s;
      return await Promise.resolve(a(r, n));
    } else {
      const a = s;
      return await Promise.resolve(a(n));
    }
  }
  /**
   * Handles automatic task polling for tools with taskSupport 'optional'.
   */
  async handleAutomaticTaskPolling(e, r, n) {
    if (!n.taskStore)
      throw new Error("No task store provided for task-capable tool.");
    const s = await this.validateToolInput(e, r.params.arguments, r.params.name), i = e.handler, a = { ...n, taskStore: n.taskStore }, o = s ? await Promise.resolve(i.createTask(s, a)) : (
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await Promise.resolve(i.createTask(a))
    ), c = o.task.taskId;
    let u = o.task;
    const l = u.pollInterval ?? 5e3;
    for (; u.status !== "completed" && u.status !== "failed" && u.status !== "cancelled"; ) {
      await new Promise((v) => setTimeout(v, l));
      const p = await n.taskStore.getTask(c);
      if (!p)
        throw new X(re.InternalError, `Task ${c} not found during polling`);
      u = p;
    }
    return await n.taskStore.getTaskResult(c);
  }
  setCompletionRequestHandler() {
    this._completionHandlerInitialized || (this.server.assertCanSetRequestHandler(At(ti)), this.server.registerCapabilities({
      completions: {}
    }), this.server.setRequestHandler(ti, async (e) => {
      switch (e.params.ref.type) {
        case "ref/prompt":
          return Wm(e), this.handlePromptCompletion(e, e.params.ref);
        case "ref/resource":
          return Jm(e), this.handleResourceCompletion(e, e.params.ref);
        default:
          throw new X(re.InvalidParams, `Invalid completion reference: ${e.params.ref}`);
      }
    }), this._completionHandlerInitialized = !0);
  }
  async handlePromptCompletion(e, r) {
    const n = this._registeredPrompts[r.name];
    if (!n)
      throw new X(re.InvalidParams, `Prompt ${r.name} not found`);
    if (!n.enabled)
      throw new X(re.InvalidParams, `Prompt ${r.name} disabled`);
    if (!n.argsSchema)
      return br;
    const s = Fr(n.argsSchema), i = s == null ? void 0 : s[e.params.argument.name];
    if (!Oc(i))
      return br;
    const a = Dv(i);
    if (!a)
      return br;
    const o = await a(e.params.argument.value, e.params.context);
    return xc(o);
  }
  async handleResourceCompletion(e, r) {
    const n = Object.values(this._registeredResourceTemplates).find((a) => a.resourceTemplate.uriTemplate.toString() === r.uri);
    if (!n) {
      if (this._registeredResources[r.uri])
        return br;
      throw new X(re.InvalidParams, `Resource template ${e.params.ref.uri} not found`);
    }
    const s = n.resourceTemplate.completeCallback(e.params.argument.name);
    if (!s)
      return br;
    const i = await s(e.params.argument.value, e.params.context);
    return xc(i);
  }
  setResourceRequestHandlers() {
    this._resourceHandlersInitialized || (this.server.assertCanSetRequestHandler(At(Js)), this.server.assertCanSetRequestHandler(At(Bs)), this.server.assertCanSetRequestHandler(At(Qs)), this.server.registerCapabilities({
      resources: {
        listChanged: !0
      }
    }), this.server.setRequestHandler(Js, async (e, r) => {
      const n = Object.entries(this._registeredResources).filter(([i, a]) => a.enabled).map(([i, a]) => ({
        uri: i,
        name: a.name,
        ...a.metadata
      })), s = [];
      for (const i of Object.values(this._registeredResourceTemplates)) {
        if (!i.resourceTemplate.listCallback)
          continue;
        const a = await i.resourceTemplate.listCallback(r);
        for (const o of a.resources)
          s.push({
            ...i.metadata,
            // the defined resource metadata should override the template metadata if present
            ...o
          });
      }
      return { resources: [...n, ...s] };
    }), this.server.setRequestHandler(Bs, async () => ({ resourceTemplates: Object.entries(this._registeredResourceTemplates).map(([r, n]) => ({
      name: r,
      uriTemplate: n.resourceTemplate.uriTemplate.toString(),
      ...n.metadata
    })) })), this.server.setRequestHandler(Qs, async (e, r) => {
      const n = new URL(e.params.uri), s = this._registeredResources[n.toString()];
      if (s) {
        if (!s.enabled)
          throw new X(re.InvalidParams, `Resource ${n} disabled`);
        return s.readCallback(n, r);
      }
      for (const i of Object.values(this._registeredResourceTemplates)) {
        const a = i.resourceTemplate.uriTemplate.match(n.toString());
        if (a)
          return i.readCallback(n, a, r);
      }
      throw new X(re.InvalidParams, `Resource ${n} not found`);
    }), this._resourceHandlersInitialized = !0);
  }
  setPromptRequestHandlers() {
    this._promptHandlersInitialized || (this.server.assertCanSetRequestHandler(At(Ys)), this.server.assertCanSetRequestHandler(At(Xs)), this.server.registerCapabilities({
      prompts: {
        listChanged: !0
      }
    }), this.server.setRequestHandler(Ys, () => ({
      prompts: Object.entries(this._registeredPrompts).filter(([, e]) => e.enabled).map(([e, r]) => ({
        name: e,
        title: r.title,
        description: r.description,
        arguments: r.argsSchema ? Kv(r.argsSchema) : void 0
      }))
    })), this.server.setRequestHandler(Xs, async (e, r) => {
      const n = this._registeredPrompts[e.params.name];
      if (!n)
        throw new X(re.InvalidParams, `Prompt ${e.params.name} not found`);
      if (!n.enabled)
        throw new X(re.InvalidParams, `Prompt ${e.params.name} disabled`);
      if (n.argsSchema) {
        const s = _r(n.argsSchema), i = await Os(s, e.params.arguments);
        if (!i.success) {
          const c = "error" in i ? i.error : "Unknown error", u = Cs(c);
          throw new X(re.InvalidParams, `Invalid arguments for prompt ${e.params.name}: ${u}`);
        }
        const a = i.data, o = n.callback;
        return await Promise.resolve(o(a, r));
      } else {
        const s = n.callback;
        return await Promise.resolve(s(r));
      }
    }), this._promptHandlersInitialized = !0);
  }
  resource(e, r, ...n) {
    let s;
    typeof n[0] == "object" && (s = n.shift());
    const i = n[0];
    if (typeof r == "string") {
      if (this._registeredResources[r])
        throw new Error(`Resource ${r} is already registered`);
      const a = this._createRegisteredResource(e, void 0, r, s, i);
      return this.setResourceRequestHandlers(), this.sendResourceListChanged(), a;
    } else {
      if (this._registeredResourceTemplates[e])
        throw new Error(`Resource template ${e} is already registered`);
      const a = this._createRegisteredResourceTemplate(e, void 0, r, s, i);
      return this.setResourceRequestHandlers(), this.sendResourceListChanged(), a;
    }
  }
  registerResource(e, r, n, s) {
    if (typeof r == "string") {
      if (this._registeredResources[r])
        throw new Error(`Resource ${r} is already registered`);
      const i = this._createRegisteredResource(e, n.title, r, n, s);
      return this.setResourceRequestHandlers(), this.sendResourceListChanged(), i;
    } else {
      if (this._registeredResourceTemplates[e])
        throw new Error(`Resource template ${e} is already registered`);
      const i = this._createRegisteredResourceTemplate(e, n.title, r, n, s);
      return this.setResourceRequestHandlers(), this.sendResourceListChanged(), i;
    }
  }
  _createRegisteredResource(e, r, n, s, i) {
    const a = {
      name: e,
      title: r,
      metadata: s,
      readCallback: i,
      enabled: !0,
      disable: () => a.update({ enabled: !1 }),
      enable: () => a.update({ enabled: !0 }),
      remove: () => a.update({ uri: null }),
      update: (o) => {
        typeof o.uri < "u" && o.uri !== n && (delete this._registeredResources[n], o.uri && (this._registeredResources[o.uri] = a)), typeof o.name < "u" && (a.name = o.name), typeof o.title < "u" && (a.title = o.title), typeof o.metadata < "u" && (a.metadata = o.metadata), typeof o.callback < "u" && (a.readCallback = o.callback), typeof o.enabled < "u" && (a.enabled = o.enabled), this.sendResourceListChanged();
      }
    };
    return this._registeredResources[n] = a, a;
  }
  _createRegisteredResourceTemplate(e, r, n, s, i) {
    const a = {
      resourceTemplate: n,
      title: r,
      metadata: s,
      readCallback: i,
      enabled: !0,
      disable: () => a.update({ enabled: !1 }),
      enable: () => a.update({ enabled: !0 }),
      remove: () => a.update({ name: null }),
      update: (u) => {
        typeof u.name < "u" && u.name !== e && (delete this._registeredResourceTemplates[e], u.name && (this._registeredResourceTemplates[u.name] = a)), typeof u.title < "u" && (a.title = u.title), typeof u.template < "u" && (a.resourceTemplate = u.template), typeof u.metadata < "u" && (a.metadata = u.metadata), typeof u.callback < "u" && (a.readCallback = u.callback), typeof u.enabled < "u" && (a.enabled = u.enabled), this.sendResourceListChanged();
      }
    };
    this._registeredResourceTemplates[e] = a;
    const o = n.uriTemplate.variableNames;
    return Array.isArray(o) && o.some((u) => !!n.completeCallback(u)) && this.setCompletionRequestHandler(), a;
  }
  _createRegisteredPrompt(e, r, n, s, i) {
    const a = {
      title: r,
      description: n,
      argsSchema: s === void 0 ? void 0 : sr(s),
      callback: i,
      enabled: !0,
      disable: () => a.update({ enabled: !1 }),
      enable: () => a.update({ enabled: !0 }),
      remove: () => a.update({ name: null }),
      update: (o) => {
        typeof o.name < "u" && o.name !== e && (delete this._registeredPrompts[e], o.name && (this._registeredPrompts[o.name] = a)), typeof o.title < "u" && (a.title = o.title), typeof o.description < "u" && (a.description = o.description), typeof o.argsSchema < "u" && (a.argsSchema = sr(o.argsSchema)), typeof o.callback < "u" && (a.callback = o.callback), typeof o.enabled < "u" && (a.enabled = o.enabled), this.sendPromptListChanged();
      }
    };
    return this._registeredPrompts[e] = a, s && Object.values(s).some((c) => {
      var l;
      const u = c instanceof It ? (l = c._def) == null ? void 0 : l.innerType : c;
      return Oc(u);
    }) && this.setCompletionRequestHandler(), a;
  }
  _createRegisteredTool(e, r, n, s, i, a, o, c, u) {
    Ac(e);
    const l = {
      title: r,
      description: n,
      inputSchema: Nc(s),
      outputSchema: Nc(i),
      annotations: a,
      execution: o,
      _meta: c,
      handler: u,
      enabled: !0,
      disable: () => l.update({ enabled: !1 }),
      enable: () => l.update({ enabled: !0 }),
      remove: () => l.update({ name: null }),
      update: (p) => {
        typeof p.name < "u" && p.name !== e && (typeof p.name == "string" && Ac(p.name), delete this._registeredTools[e], p.name && (this._registeredTools[p.name] = l)), typeof p.title < "u" && (l.title = p.title), typeof p.description < "u" && (l.description = p.description), typeof p.paramsSchema < "u" && (l.inputSchema = sr(p.paramsSchema)), typeof p.outputSchema < "u" && (l.outputSchema = sr(p.outputSchema)), typeof p.callback < "u" && (l.handler = p.callback), typeof p.annotations < "u" && (l.annotations = p.annotations), typeof p._meta < "u" && (l._meta = p._meta), typeof p.enabled < "u" && (l.enabled = p.enabled), this.sendToolListChanged();
      }
    };
    return this._registeredTools[e] = l, this.setToolRequestHandlers(), this.sendToolListChanged(), l;
  }
  /**
   * tool() implementation. Parses arguments passed to overrides defined above.
   */
  tool(e, ...r) {
    if (this._registeredTools[e])
      throw new Error(`Tool ${e} is already registered`);
    let n, s, i, a;
    if (typeof r[0] == "string" && (n = r.shift()), r.length > 1) {
      const c = r[0];
      if (ki(c))
        s = r.shift(), r.length > 1 && typeof r[0] == "object" && r[0] !== null && !ki(r[0]) && (a = r.shift());
      else if (typeof c == "object" && c !== null) {
        if (Object.values(c).some((u) => typeof u == "object" && u !== null))
          throw new Error(`Tool ${e} expected a Zod schema or ToolAnnotations, but received an unrecognized object`);
        a = r.shift();
      }
    }
    const o = r[0];
    return this._createRegisteredTool(e, void 0, n, s, i, a, { taskSupport: "forbidden" }, void 0, o);
  }
  /**
   * Registers a tool with a config object and callback.
   */
  registerTool(e, r, n) {
    if (this._registeredTools[e])
      throw new Error(`Tool ${e} is already registered`);
    const { title: s, description: i, inputSchema: a, outputSchema: o, annotations: c, _meta: u } = r;
    return this._createRegisteredTool(e, s, i, a, o, c, { taskSupport: "forbidden" }, u, n);
  }
  prompt(e, ...r) {
    if (this._registeredPrompts[e])
      throw new Error(`Prompt ${e} is already registered`);
    let n;
    typeof r[0] == "string" && (n = r.shift());
    let s;
    r.length > 1 && (s = r.shift());
    const i = r[0], a = this._createRegisteredPrompt(e, void 0, n, s, i);
    return this.setPromptRequestHandlers(), this.sendPromptListChanged(), a;
  }
  /**
   * Registers a prompt with a config object and callback.
   */
  registerPrompt(e, r, n) {
    if (this._registeredPrompts[e])
      throw new Error(`Prompt ${e} is already registered`);
    const { title: s, description: i, argsSchema: a } = r, o = this._createRegisteredPrompt(e, s, i, a, n);
    return this.setPromptRequestHandlers(), this.sendPromptListChanged(), o;
  }
  /**
   * Checks if the server is connected to a transport.
   * @returns True if the server is connected
   */
  isConnected() {
    return this.server.transport !== void 0;
  }
  /**
   * Sends a logging message to the client, if connected.
   * Note: You only need to send the parameters object, not the entire JSON RPC message
   * @see LoggingMessageNotification
   * @param params
   * @param sessionId optional for stateless and backward compatibility
   */
  async sendLoggingMessage(e, r) {
    return this.server.sendLoggingMessage(e, r);
  }
  /**
   * Sends a resource list changed event to the client, if connected.
   */
  sendResourceListChanged() {
    this.isConnected() && this.server.sendResourceListChanged();
  }
  /**
   * Sends a tool list changed event to the client, if connected.
   */
  sendToolListChanged() {
    this.isConnected() && this.server.sendToolListChanged();
  }
  /**
   * Sends a prompt list changed event to the client, if connected.
   */
  sendPromptListChanged() {
    this.isConnected() && this.server.sendPromptListChanged();
  }
}
const Vv = {
  type: "object",
  properties: {}
};
function vl(t) {
  return t !== null && typeof t == "object" && "parse" in t && typeof t.parse == "function" && "safeParse" in t && typeof t.safeParse == "function";
}
function wl(t) {
  return "_def" in t || "_zod" in t || vl(t);
}
function ki(t) {
  return typeof t != "object" || t === null || wl(t) ? !1 : Object.keys(t).length === 0 ? !0 : Object.values(t).some(vl);
}
function Nc(t) {
  if (t) {
    if (ki(t))
      return sr(t);
    if (!wl(t))
      throw new Error("inputSchema must be a Zod schema or raw shape, received an unrecognized object");
    return t;
  }
}
function Kv(t) {
  const e = Fr(t);
  return e ? Object.entries(e).map(([r, n]) => {
    const s = A_(n), i = N_(n);
    return {
      name: r,
      description: s,
      required: !i
    };
  }) : [];
}
function At(t) {
  const e = Fr(t), r = e == null ? void 0 : e.method;
  if (!r)
    throw new Error("Schema is missing a method literal");
  const n = rl(r);
  if (typeof n == "string")
    return n;
  throw new Error("Schema method literal must be a string");
}
function xc(t) {
  return {
    completion: {
      values: t.slice(0, 100),
      total: t.length,
      hasMore: t.length > 100
    }
  };
}
const br = {
  completion: {
    values: [],
    hasMore: !1
  }
};
async function Gv(t, e) {
  var n, s, i;
  if (!((n = window.jetEngineCompatibilityAngie) != null && n.api_base))
    throw new Error("API base URL is not defined");
  const r = await fetch(
    ((s = window.jetEngineCompatibilityAngie) == null ? void 0 : s.api_base) + t,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": ((i = window.jetEngineCompatibilityAngie) == null ? void 0 : i.nonce) || ""
      },
      body: JSON.stringify({ input: e })
    }
  );
  if (!r.ok)
    throw new Error(`HTTP error! status: ${r.status}`);
  return await r.json();
}
function bl(t) {
  const e = {};
  for (const r in t)
    e[r] = Sl(t[r]);
  return e;
}
const Sl = (t) => {
  let e;
  switch (t.type) {
    case "string":
      t.enum ? e = P_(t.enum) : e = Ps();
      break;
    case "number":
      e = $_();
      break;
    case "boolean":
      e = E_();
      break;
    case "array":
      e = R_(Sl(t.items));
      break;
    case "object":
      t.properties ? e = tl(bl(t.properties)) : e = I_(Ps(), T_());
      break;
    default:
      e = Ps();
  }
  return t.description && (e = e.describe(t.description)), e;
};
function Wv() {
  var e;
  const t = new Hv(
    {
      name: "croco-angie-mcp-server",
      version: "1.0.0"
    },
    {
      capabilities: {
        tools: {}
      }
    }
  );
  for (const r of ((e = window.jetEngineCompatibilityAngie) == null ? void 0 : e.features) || []) {
    let n = r.id;
    n = n.replace(/\//g, "-"), n = n.replace(/-/g, "_"), t.registerTool(
      n,
      {
        title: r.label,
        description: r.description,
        inputSchema: bl(r.input_schema.properties)
      },
      async (s, i) => {
        const a = await Gv(r.name, s);
        return console.log(i), {
          content: [{
            type: "text",
            text: JSON.stringify(a, null, 2)
          }]
        };
      }
    );
  }
  return t;
}
const Jv = async () => {
  try {
    const t = Wv();
    await new Qg().registerServer({
      name: "croco-angie-mcp-server",
      version: "1.0.0",
      description: "Crocoblock MCP Server for Angie AI assistant",
      server: t
    }), console.log("Crocoblock MCP Server registered with Angie successfully");
  } catch (t) {
    console.error("Failed to register Crocoblock MCP Server with Angie:", t);
  }
};
Jv();

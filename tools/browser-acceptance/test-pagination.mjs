import assert from "node:assert/strict";
import { chooseNextPaginationIndex } from "./etg-driver.mjs";

assert.equal(chooseNextPaginationIndex([
  { visible: true, value: 2, className: "", ariaLabel: "" },
  { visible: true, value: 9, className: "", ariaLabel: "" },
  { visible: true, value: null, className: "next", ariaLabel: "" }
]), 2);

assert.equal(chooseNextPaginationIndex([
  { visible: true, value: 1, className: "", ariaCurrent: "" },
  { visible: true, value: 2, className: "active", ariaCurrent: "page" },
  { visible: true, value: 9, className: "", ariaCurrent: "" },
  { visible: true, value: 3, className: "", ariaCurrent: "" },
  { visible: true, value: 4, className: "", ariaCurrent: "" }
]), 3);

assert.equal(chooseNextPaginationIndex([
  { visible: true, value: 1, className: "active", ariaCurrent: "page" },
  { visible: false, value: 2, className: "", ariaCurrent: "" },
  { visible: true, value: 3, className: "", ariaCurrent: "" }
]), 2);

assert.equal(chooseNextPaginationIndex([
  { visible: true, value: 3, className: "active", ariaCurrent: "page" },
  { visible: true, value: 1, className: "", ariaCurrent: "" },
  { visible: true, value: 2, className: "", ariaCurrent: "" }
]), -1);

console.log("MAD4B browser sequential pagination PASS");

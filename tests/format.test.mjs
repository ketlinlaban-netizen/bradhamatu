import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("../src/utils/format.ts", import.meta.url), "utf8");
const compiled = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020 },
}).outputText;
const { formatSignal } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`);

test("formatSignal displays unavailable and zero signal as an em dash", () => {
  assert.equal(formatSignal(null), "—");
  assert.equal(formatSignal(undefined), "—");
  assert.equal(formatSignal(0), "—");
});

test("formatSignal preserves measured dBm values", () => {
  assert.equal(formatSignal(-55), "-55 dBm");
});

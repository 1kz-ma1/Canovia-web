import assert from 'node:assert/strict';
import test from 'node:test';
import shellQuote from 'shell-quote';
import sourceMap from 'source-map-js';

// Only inspect library output. Never invoke a shell or consume host files.
test('shell quoting rejects line terminators after a comment token', () => {
    for (const ending of ['\n', '\r', '\u2028', '\u2029']) {
        assert.throws(() => shellQuote.quote(['echo', { comment: 'synthetic' }, `a${ending}blocked`]), TypeError);
    }
    assert.deepEqual(shellQuote.parse(shellQuote.quote(['echo', 'two words'])), ['echo', 'two words']);
});

test('indexed source maps reject excessive offsets before consuming mappings', () => {
    const map = { version: 3, sources: ['synthetic.js'], names: [], mappings: 'AAAA' };
    const indexed = line => ({ version: 3, sections: [{ offset: { line, column: 0 }, map }] });
    // Constructor only: do not exercise the historical unbounded mapping loop.
    assert.throws(() => new sourceMap.SourceMapConsumer(indexed(Number.MAX_SAFE_INTEGER)), /offset/i);
    assert.doesNotThrow(() => new sourceMap.SourceMapConsumer(indexed(0)));
    const consumer = new sourceMap.SourceMapConsumer(map);
    assert.deepEqual(consumer.originalPositionFor({ line: 1, column: 0 }),
        { source: 'synthetic.js', line: 1, column: 0, name: null });
});

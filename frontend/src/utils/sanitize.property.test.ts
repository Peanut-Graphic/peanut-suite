import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { sanitizeText, sanitizeHtml } from './sanitize';

/**
 * Property-based tests (Testing Protocol v2, Net 6) for the pure sanitize utils.
 *
 * sanitizeText / sanitizeHtml are pure transforms over a string (DOMPurify in jsdom,
 * no network, no app state). We assert REAL invariants over many randomized inputs.
 * A failing property is a real XSS-relevant bug — report it, never weaken the test.
 */
describe('sanitize utils — property based', () => {
  it('sanitizeText never emits an HTML tag, for any input', () => {
    fc.assert(
      fc.property(fc.string(), (input) => {
        const out = sanitizeText(input);
        // ALLOWED_TAGS: [] strips every element — no angle-bracketed tag survives.
        expect(/<[a-zA-Z!/]/.test(out)).toBe(false);
      }),
      { numRuns: 500 }
    );
  });

  it('sanitizeText is idempotent (sanitizing twice == sanitizing once)', () => {
    fc.assert(
      fc.property(fc.string(), (input) => {
        const once = sanitizeText(input);
        const twice = sanitizeText(once);
        expect(twice).toBe(once);
      }),
      { numRuns: 500 }
    );
  });

  it('sanitizeHtml never keeps a <script> tag or javascript: URI', () => {
    // Bias the generator toward dangerous fragments so the property is meaningful.
    const danger = fc.oneof(
      fc.constant('<script>alert(1)</script>'),
      fc.constant('<img src=x onerror=alert(1)>'),
      fc.constant('<a href="javascript:alert(1)">x</a>'),
      fc.string(),
    );
    fc.assert(
      fc.property(fc.array(danger), (parts) => {
        const out = sanitizeHtml(parts.join(''));
        expect(out.toLowerCase()).not.toContain('<script');
        expect(out.toLowerCase()).not.toContain('javascript:');
        expect(out.toLowerCase()).not.toContain('onerror=');
      }),
      { numRuns: 500 }
    );
  });

  it('sanitizeHtml is idempotent', () => {
    fc.assert(
      fc.property(fc.string(), (input) => {
        const once = sanitizeHtml(input);
        expect(sanitizeHtml(once)).toBe(once);
      }),
      { numRuns: 300 }
    );
  });
});

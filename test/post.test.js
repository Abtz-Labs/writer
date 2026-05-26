import { vi, describe, test, expect } from 'vitest';
import Post from '../models/post.js';

vi.mock('marked', () => {
  function parse(text) {
    return `<p>${text}</p>`;
  }

  function setOptions() {}

  const marked = parse;
  marked.parse = parse;
  marked.setOptions = setOptions;
  marked.defaults = {};
  marked.getDefaults = () => ({});
  marked.use = () => {};
  marked.walkTokens = () => {};
  marked.parseInline = () => '';
  marked.Parser = { parse: () => '' };
  marked.parser = () => '';
  marked.Renderer = class Renderer {};
  marked.TextRenderer = class TextRenderer {};
  marked.Lexer = { lex: () => [] };
  marked.lexer = () => [];
  marked.Tokenizer = class Tokenizer {};
  marked.Hooks = class Hooks {};

  return {
    marked,
    parse,
    setOptions,
    defaults: {},
    getDefaults: () => ({}),
    use: () => {},
    walkTokens: () => {},
    parseInline: () => '',
    Parser: { parse: () => '' },
    parser: () => '',
    Renderer: class Renderer {},
    TextRenderer: class TextRenderer {},
    Lexer: { lex: () => [] },
    lexer: () => [],
    Tokenizer: class Tokenizer {},
    Hooks: class Hooks {},
    default: marked
  };
});

describe('Post Model', () => {
  describe('toView / toApiJSON', () => {
    test('preserves gist embed script tags', () => {
      const post = new Post({
        title: 'Test',
        body: '<script src="https://gist.github.com/dsternlicht/7a21172970f6f9cde61eb4a8cfee30d5.js"></script>\n\nSome text after.',
        slug: 'test',
        status: 'published'
      });
      const view = post.toView();
      expect(view.bodyHtml).toContain('<script src="https://gist.github.com/dsternlicht/7a21172970f6f9cde61eb4a8cfee30d5.js"></script>');
    });

    test('strips non-gist script tags', () => {
      const post = new Post({
        title: 'Test',
        body: '<script src="https://evil.com/script.js"></script>\n\nSome text after.',
        slug: 'test',
        status: 'published'
      });
      const view = post.toView();
      expect(view.bodyHtml).not.toContain('<script');
      expect(view.bodyHtml).not.toContain('evil.com');
    });

    test('strips inline script tags', () => {
      const post = new Post({
        title: 'Test',
        body: '<script>alert(1)</script>\n\nSome text after.',
        slug: 'test',
        status: 'published'
      });
      const view = post.toView();
      expect(view.bodyHtml).not.toContain('<script');
      expect(view.bodyHtml).not.toContain('alert');
    });
  });
});

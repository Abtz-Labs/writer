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

module.exports = {
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
};

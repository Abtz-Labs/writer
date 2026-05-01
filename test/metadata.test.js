const metadata = require('../services/metadata');

describe('Metadata Service', () => {
  describe('generateSlug', () => {
    test('converts title to lowercase slug', () => {
      expect(metadata.generateSlug('Hello World')).toBe('hello-world');
    });

    test('removes special characters', () => {
      expect(metadata.generateSlug('Test @# Post!')).toBe('test-post');
    });

    test('handles multiple spaces', () => {
      expect(metadata.generateSlug('Multiple   Spaces')).toBe('multiple-spaces');
    });

    test('handles empty string', () => {
      expect(metadata.generateSlug('')).toBe('');
    });
  });

  describe('generateUniqueSlugFromList', () => {
    test('returns base slug when not in list', () => {
      const posts = [{ slug: 'other' }];
      expect(metadata.generateUniqueSlugFromList(posts, 'hello')).toBe('hello');
    });

    test('appends -1 when slug exists', () => {
      const posts = [{ slug: 'hello' }];
      expect(metadata.generateUniqueSlugFromList(posts, 'hello')).toBe('hello-1');
    });

    test('finds next available number', () => {
      const posts = [{ slug: 'hello' }, { slug: 'hello-1' }, { slug: 'hello-2' }];
      expect(metadata.generateUniqueSlugFromList(posts, 'hello')).toBe('hello-3');
    });

    test('excludes specific id from check', () => {
      const posts = [{ id: '1', slug: 'hello' }, { id: '2', slug: 'hello-1' }];
      expect(metadata.generateUniqueSlugFromList(posts, 'hello', '1')).toBe('hello');
    });
  });

  describe('extractKeywords', () => {
    test('extracts keywords from title and body', () => {
      const keywords = metadata.extractKeywords('JavaScript Tutorial', 'Learn JavaScript programming with examples and tutorials');
      expect(keywords).toContain('javascript');
      expect(keywords).toContain('tutorial');
    });

    test('filters common stop words', () => {
      const keywords = metadata.extractKeywords('The Test', 'the a an and but');
      expect(keywords).not.toContain('the');
    });

    test('returns empty for empty input', () => {
      expect(metadata.extractKeywords('', '')).toBe('');
    });
  });

  describe('generateMetaDescription', () => {
    test('truncates long text to 160 chars', () => {
      const longText = 'A'.repeat(200);
      const desc = metadata.generateMetaDescription(longText);
      expect(desc.length).toBeLessThanOrEqual(160);
      expect(desc.endsWith('...')).toBe(true);
    });

    test('keeps short text as is', () => {
      expect(metadata.generateMetaDescription('Short')).toBe('Short');
    });
  });

  describe('calculateReadingTime', () => {
    test('calculates based on 200 words per minute', () => {
      const text = 'word '.repeat(200);
      expect(metadata.calculateReadingTime(text)).toBe(1);
    });

    test('rounds up', () => {
      const text = 'word '.repeat(201);
      expect(metadata.calculateReadingTime(text)).toBe(2);
    });

    test('minimum is 1 minute', () => {
      expect(metadata.calculateReadingTime('short')).toBe(1);
    });
  });

  describe('generateExcerpt', () => {
    test('truncates to max length', () => {
      const longText = 'A'.repeat(300);
      const excerpt = metadata.generateExcerpt(longText, 200);
      expect(excerpt.length).toBeLessThanOrEqual(200);
    });

    test('removes markdown syntax', () => {
      const withMarkdown = '# Header\n![alt](img.jpg)\n[link](url.com)\n**bold** and ~strike~';
      const excerpt = metadata.generateExcerpt(withMarkdown);
      expect(excerpt).not.toContain('[');
      expect(excerpt).not.toContain('!');
      expect(excerpt).not.toContain('*');
      expect(excerpt).not.toContain('~');
    });

    test('removes strikethrough content entirely', () => {
      const withStrikethrough = 'This is ~removed~ text with ~~also removed~~ content';
      const excerpt = metadata.generateExcerpt(withStrikethrough);
      expect(excerpt).not.toContain('removed');
      expect(excerpt).not.toContain('also removed');
      expect(excerpt).toBe('This is  text with  content');
    });
  });

  describe('inferMetadata', () => {
    test('returns all metadata fields', () => {
      const result = metadata.inferMetadata('Test Title', 'This is the body content');
      expect(result).toHaveProperty('slug');
      expect(result).toHaveProperty('keywords');
      expect(result).toHaveProperty('meta_description');
      expect(result).toHaveProperty('reading_time');
      expect(result).toHaveProperty('excerpt');
    });

    test('generates slug from title', () => {
      const result = metadata.inferMetadata('My Test Post', '');
      expect(result.slug).toBe('my-test-post');
    });
  });
});
const Post = require('../models/post');

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

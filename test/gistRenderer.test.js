import { vi, describe, test, expect, beforeEach } from 'vitest';

const mockHttpsGet = vi.fn();

vi.mock('node:https', () => ({
  default: { get: mockHttpsGet },
  get: mockHttpsGet
}));

const { renderGistsInHtml } = await import('../services/gistRenderer.js');

describe('Gist Renderer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  test('returns html unchanged when no gist scripts present', async () => {
    const html = '<p>Hello world</p>';
    const result = await renderGistsInHtml(html);
    expect(result).toBe(html);
  });

  test('renders gist files into styled code blocks', async () => {
    const mockGistData = {
      html_url: 'https://gist.github.com/user/abc123',
      files: {
        'hello.js': {
          filename: 'hello.js',
          content: 'const x = 1;',
          language: 'JavaScript'
        }
      }
    };

    const mockResponse = {
      statusCode: 200,
      setEncoding: vi.fn(),
      on: vi.fn((event, callback) => {
        if (event === 'data') {
          callback(JSON.stringify(mockGistData));
        } else if (event === 'end') {
          callback();
        }
      })
    };

    mockHttpsGet.mockImplementation((url, options, callback) => {
      callback(mockResponse);
      return { on: vi.fn() };
    });

    const html = '<script src="https://gist.github.com/user/abc123.js"></script>';
    const result = await renderGistsInHtml(html);

    expect(result).not.toContain('<script');
    expect(result).toContain('gist-embed');
    expect(result).toContain('gist-file');
    expect(result).toContain('hello.js');
    expect(result).toContain('hljs-keyword');
    expect(result).toContain('hljs-number');
    expect(result).toContain('hljs');
  });

  test('shows fallback on gist fetch failure', async () => {
    const mockResponse = {
      statusCode: 404,
      setEncoding: vi.fn(),
      on: vi.fn((event, callback) => {
        if (event === 'data') {
          callback('Not Found');
        } else if (event === 'end') {
          callback();
        }
      })
    };

    mockHttpsGet.mockImplementation((url, options, callback) => {
      callback(mockResponse);
      return { on: vi.fn() };
    });

    const html = '<script src="https://gist.github.com/user/deadbeef.js"></script>';
    const result = await renderGistsInHtml(html);

    expect(result).not.toContain('<script');
    expect(result).toContain('gist-error');
    expect(result).toContain('Unable to load gist');
  });
});

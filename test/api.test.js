const request = require('supertest');
const app = require('../app');

describe('API Basic Tests', () => {
  test('GET /api returns docs', async () => {
    const res = await request(app).get('/api');
    expect(res.status).toBe(200);
    expect(res.body.name).toBe('Serif Blog API');
  });
  
  test('GET /api/posts returns array', async () => {
    const res = await request(app).get('/api/posts');
    expect(res.status).toBe(200);
    expect(Array.isArray(res.body.posts)).toBe(true);
  });
  
  test('GET /api/settings returns status', async () => {
    const res = await request(app).get('/api/settings');
    expect(res.status).toBe(200);
    expect('onboarding_complete' in res.body).toBe(true);
  });
});
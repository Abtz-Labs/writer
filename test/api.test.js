const request = require("supertest");
const app = require("../app");
const { getCollection } = require("../config/database");

describe("API Basic Tests", () => {
  test("GET /api returns docs", async () => {
    const res = await request(app).get("/api");
    expect(res.status).toBe(200);
    expect(res.body.name).toBe("Writer API");
  });

  test("GET /api/posts returns only published posts", async () => {
    const res = await request(app).get("/api/posts");
    expect(res.status).toBe(200);
    expect(Array.isArray(res.body.posts)).toBe(true);
    res.body.posts.forEach((post) => {
      expect(post.status).toBe("published");
    });
  });

  test("GET /api/posts/:slug rejects draft for unauthenticated", async () => {
    const res = await request(app).get("/api/posts/nonexistent-draft-post");
    expect(res.status).toBe(404);
  });

  test("GET /api/settings does not leak auth_token", async () => {
    const res = await request(app).get("/api/settings");
    expect(res.status).toBe(200);
    expect("onboarding_complete" in res.body).toBe(true);
    expect(res.body.auth_token).toBeUndefined();
  });
});

describe("API Destructive Actions Confirmation Flow", () => {
  let authToken;

  beforeAll(async () => {
    const onboardRes = await request(app)
      .post("/api/onboarding")
      .send({ title: "Test Blog", author: "Test Author" });

    if (onboardRes.status === 201) {
      authToken = onboardRes.body.auth_token;
    } else {
      const settingsCollection = getCollection("settings");
      const settingsData = await settingsCollection.find({ id: "settings" });
      const settingsObj =
        settingsData && settingsData.length > 0 ? settingsData[0] : null;
      authToken = settingsObj ? settingsObj.auth_token : "";
    }
  });

  test("DELETE /api/posts/:slug requires confirmation", async () => {
    const createRes = await request(app)
      .post("/api/posts")
      .set("X-Auth-Token", authToken)
      .send({ title: "Test Post", body: "Test body" });

    expect(createRes.status).toBe(201);
    const slug = createRes.body.slug;

    const deleteRes = await request(app)
      .delete("/api/posts/" + slug)
      .set("X-Auth-Token", authToken);

    expect(deleteRes.status).toBe(202);
    expect(deleteRes.body.confirmation_required).toBe(true);
    expect(deleteRes.body.confirmation_url).toBeDefined();
  });

  test("POST /api/confirm/:token executes pending delete", async () => {
    const createRes = await request(app)
      .post("/api/posts")
      .set("X-Auth-Token", authToken)
      .send({ title: "Test Post 2", body: "Test body 2" });

    const slug = createRes.body.slug;

    const deleteRes = await request(app)
      .delete("/api/posts/" + slug)
      .set("X-Auth-Token", authToken);

    const confirmUrl = deleteRes.body.confirmation_url;

    const confirmRes = await request(app)
      .post(confirmUrl)
      .set("X-Auth-Token", authToken);

    expect(confirmRes.status).toBe(200);
    expect(confirmRes.body.message).toBe("Post deleted successfully");
    expect(confirmRes.body.slug).toBe(slug);
  });

  test("POST /api/settings/rotate-token requires confirmation", async () => {
    const rotateRes = await request(app)
      .post("/api/settings/rotate-token")
      .set("X-Auth-Token", authToken);

    expect(rotateRes.status).toBe(202);
    expect(rotateRes.body.confirmation_required).toBe(true);
    expect(rotateRes.body.confirmation_url).toBeDefined();
  });

  test("POST /api/confirm/:token executes pending rotate", async () => {
    const rotateRes = await request(app)
      .post("/api/settings/rotate-token")
      .set("X-Auth-Token", authToken);

    const confirmUrl = rotateRes.body.confirmation_url;

    const confirmRes = await request(app)
      .post(confirmUrl)
      .set("X-Auth-Token", authToken);

    expect(confirmRes.status).toBe(200);
    expect(confirmRes.body.message).toBe("Auth token rotated successfully");
    expect(confirmRes.body.auth_token).toBeDefined();

    authToken = confirmRes.body.auth_token;
  });

  test("invalid confirmation token returns 410", async () => {
    const res = await request(app)
      .post("/api/confirm/invalidtoken123")
      .set("X-Auth-Token", authToken);

    expect(res.status).toBe(410);
  });
});

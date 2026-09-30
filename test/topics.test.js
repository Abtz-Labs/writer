import { describe, test, expect, beforeAll, afterAll } from "vitest";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), "writer-topics-"));
process.env.STORAGE_PATH = tmpDir;

const { default: request } = await import("supertest");
const { default: app } = await import("../app.js");
const { closeDB } = await import("../config/database.js");

const TAGS = ["alpha", "beta", "gamma", "delta"];

describe("Topics block", () => {
  let authToken;

  beforeAll(async () => {
    const onboardRes = await request(app)
      .post("/api/onboarding")
      .send({ title: "Topics Test" });
    authToken = onboardRes.body.auth_token;

    for (const tag of TAGS) {
      await request(app)
        .post("/api/posts")
        .set("X-Auth-Token", authToken)
        .send({
          title: `Post ${tag}`,
          body: `Body ${tag}`,
          tags: [tag],
          status: "published",
        });
    }
  });

  afterAll(async () => {
    closeDB();
    fs.rmSync(tmpDir, { recursive: true, force: true });
  });

  describe("mobile expand control", () => {
    test("GET / renders the expand control", async () => {
      const res = await request(app).get("/");
      expect(res.status).toBe(200);
      expect(res.text).toContain('class="tag tag-list-toggle"');
      expect(res.text).toContain('aria-controls="tag-list"');
      expect(res.text).toContain('aria-expanded="false"');
    });

    test("GET /search renders the expand control", async () => {
      const res = await request(app).get("/search?q=post");
      expect(res.status).toBe(200);
      expect(res.text).toContain('class="tag tag-list-toggle"');
      expect(res.text).toContain('aria-controls="tag-list"');
      expect(res.text).toContain('aria-expanded="false"');
    });

    test("expand control carries both labels for the collapsed and expanded state", async () => {
      const res = await request(app).get("/");
      expect(res.text).toContain('class="tag-list-more"');
      expect(res.text).toContain("Show all topics");
      expect(res.text).toContain('class="tag-list-less"');
      expect(res.text).toContain("Show less");
    });
  });

  describe("tag list", () => {
    test("GET / renders an anchor per tag plus All", async () => {
      const res = await request(app).get("/?tag=alpha");
      expect(res.text).toMatch(/class="tag"\s+data-filter=""/);
      for (const tag of TAGS) {
        expect(res.text).toContain(`data-filter="${tag}"`);
      }
    });

    test("GET /search renders an anchor per tag plus All", async () => {
      const res = await request(app).get("/search?q=post");
      expect(res.text).toMatch(/class="tag"\s+data-filter=""/);
      for (const tag of TAGS) {
        expect(res.text).toContain(`data-filter="${tag}"`);
      }
    });
  });

  describe("active tag state", () => {
    test("GET / with no tag marks All as active", async () => {
      const res = await request(app).get("/");
      expect(res.text).toMatch(/class="tag is-active"\s+data-filter=""/);
    });

    test("GET / with a tag marks that tag as active", async () => {
      const res = await request(app).get("/?tag=alpha");
      expect(res.text).toMatch(/class="tag is-active"\s+data-filter="alpha"/);
      expect(res.text).toMatch(/class="tag"\s+data-filter="beta"/);
    });

    test("GET /search marks no tag as active", async () => {
      const res = await request(app).get("/search?q=post");
      expect(res.text).not.toContain("is-active");
    });
  });
});

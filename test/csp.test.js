import request from "supertest";
import app from "../app.js";
import { getCollection } from "../config/database.js";

describe("CSP Script Domains", () => {
  let authToken;

  beforeAll(async () => {
    const onboardRes = await request(app)
      .post("/api/onboarding")
      .send({ title: "CSP Test Blog", author: "Test Author" });

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

  test("default CSP scriptSrc includes only self and unsafe-inline", async () => {
    const res = await request(app).get("/");
    const csp = res.headers["content-security-policy"];
    expect(csp).toContain("script-src 'self' 'unsafe-inline'");
    expect(csp).not.toContain("googletagmanager.com");
  });

  test("CSP includes domains from csp_script_domains setting", async () => {
    await request(app)
      .put("/api/settings")
      .set("X-Auth-Token", authToken)
      .send({ csp_script_domains: "https://www.googletagmanager.com, https://www.google-analytics.com" });

    const res = await request(app).get("/");
    const csp = res.headers["content-security-policy"];
    expect(csp).toContain("https://www.googletagmanager.com");
    expect(csp).toContain("https://www.google-analytics.com");
  });

  test("CSP ignores empty or whitespace-only domains", async () => {
    await request(app)
      .put("/api/settings")
      .set("X-Auth-Token", authToken)
      .send({ csp_script_domains: "https://example.com, , ,  " });

    const res = await request(app).get("/");
    const csp = res.headers["content-security-policy"];
    expect(csp).toContain("https://example.com");
  });

  test("CSP resets when csp_script_domains is cleared", async () => {
    await request(app)
      .put("/api/settings")
      .set("X-Auth-Token", authToken)
      .send({ csp_script_domains: "" });

    const res = await request(app).get("/");
    const csp = res.headers["content-security-policy"];
    expect(csp).toContain("script-src 'self' 'unsafe-inline'");
    expect(csp).not.toContain("https://example.com");
  });

  test("csp_script_domains is persisted and returned from settings API", async () => {
    await request(app)
      .put("/api/settings")
      .set("X-Auth-Token", authToken)
      .send({ csp_script_domains: "https://cdn.example.com" });

    const res = await request(app).get("/api/settings");
    expect(res.body.csp_script_domains).toBe("https://cdn.example.com");
  });
});

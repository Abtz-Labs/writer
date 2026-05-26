import { marked } from "marked";

import { getCollection, forceSavePost } from "../config/database.js";

import Post from "../models/post.js";

import * as metadata from "../services/metadata.js";
import confirmationService from "../services/confirmation.js";
import * as ogImage from "../services/ogImage.js";
import { renderGistsInHtml } from "../services/gistRenderer.js";

import logger from "../utils/logger.js";

import { v4 as uuidv4 } from "uuid";

marked.setOptions({
  breaks: true,
  gfm: true,
});

function validatePostInput(data, isUpdate = false) {
  const { title, body, tags, status, published_at } = data;
  const errors = [];

  if (!isUpdate || title !== undefined) {
    if (
      !isUpdate &&
      (!title || typeof title !== "string" || title.trim().length === 0)
    ) {
      errors.push("Title is required and must be a non-empty string");
    }
    if (title !== undefined && typeof title !== "string") {
      errors.push("Title must be a string");
    }
    if (title && title.length > 200) {
      errors.push("Title must not exceed 200 characters");
    }
  }

  if (!isUpdate || body !== undefined) {
    if (
      !isUpdate &&
      (!body || typeof body !== "string" || body.trim().length === 0)
    ) {
      errors.push("Body is required and must be a non-empty string");
    }
    if (body !== undefined && typeof body !== "string") {
      errors.push("Body must be a string");
    }
    if (body && body.length > 50000) {
      errors.push("Body must not exceed 50000 characters");
    }
  }

  if (tags !== undefined) {
    if (
      !Array.isArray(tags) ||
      !tags.every((t) => typeof t === "string" && t.length <= 50)
    ) {
      errors.push(
        "Tags must be an array of strings, each not exceeding 50 characters",
      );
    }
  }

  if (status !== undefined && !["draft", "published"].includes(status)) {
    errors.push('Status must be either "draft" or "published"');
  }

  if (published_at !== undefined && published_at !== null) {
    const date = new Date(published_at);
    if (isNaN(date.getTime())) {
      errors.push("published_at must be a valid ISO 8601 date string");
    }
  }

  return errors;
}

class PostController {
  async getAll(req, res, next) {
    try {
      const postsCollection = getCollection("posts");
      const postsData = await postsCollection.find();
      const posts = await Promise.all(
        (postsData || [])
          .map((p) => Post.fromDB(p).toApiJSON())
          .filter((p) => p.status === "published")
          .sort((a, b) => new Date(b.published_at) - new Date(a.published_at))
          .map(async (p) => {
            p.bodyHtml = await renderGistsInHtml(p.bodyHtml);
            return p;
          }),
      );

      const page = Math.max(1, parseInt(req.query.page) || 1);
      const limit = Math.min(Math.max(1, parseInt(req.query.limit) || 10), 100);
      const start = (page - 1) * limit;
      const end = start + limit;

      const paginatedPosts = posts.slice(start, end);

      res.json({
        posts: paginatedPosts,
        pagination: {
          page,
          limit,
          total: posts.length,
          pages: Math.ceil(posts.length / limit),
        },
      });
    } catch (err) {
      next(err);
    }
  }

  async getBySlug(req, res, next) {
    try {
      const postsCollection = getCollection("posts");
      const allPosts = (await postsCollection.find()) || [];
      const post = allPosts.find((p) => p.slug === req.params.slug);

      if (!post || (post.status !== "published" && !req.settings)) {
        return res.status(404).json({
          error: "Not Found",
          message: "Post not found",
        });
      }

      const apiJson = Post.fromDB(post).toApiJSON();
      apiJson.bodyHtml = await renderGistsInHtml(apiJson.bodyHtml);
      res.json(apiJson);
    } catch (err) {
      next(err);
    }
  }

  async create(req, res, next) {
    try {
      const { title, body, tags, cover_image, status, published_at } = req.body;

      const validationErrors = validatePostInput(req.body);
      if (validationErrors.length > 0) {
        return res.status(400).json({
          error: "Validation Error",
          message: validationErrors.join("; "),
        });
      }

      const postsCollection = getCollection("posts");

      const allPosts = (await postsCollection.find()) || [];
      const inferred = metadata.inferMetadata(title, body);
      const slug = metadata.generateUniqueSlugFromList(allPosts, inferred.slug);

      const createdAt = new Date().toISOString();
      const postData = {
        id: uuidv4(),
        title,
        slug,
        body,
        cover_image: cover_image || "",
        excerpt: inferred.excerpt,
        keywords: inferred.keywords,
        meta_description: inferred.meta_description,
        reading_time: inferred.reading_time,
        status: status || "draft",
        tags: tags || [],
        created_at: createdAt,
        updated_at: createdAt,
        published_at: published_at || createdAt,
      };

      await postsCollection.insert(postData);

      const post = Post.fromDB(postData);
      const apiJson = post.toApiJSON();
      apiJson.bodyHtml = await renderGistsInHtml(apiJson.bodyHtml);
      res.status(201).json(apiJson);
    } catch (err) {
      next(err);
    }
  }

  async update(req, res, next) {
    try {
      const postsCollection = getCollection("posts");
      const allPosts = (await postsCollection.find()) || [];
      const post = allPosts.find((p) => p.slug === req.params.slug);

      if (!post) {
        return res.status(404).json({
          error: "Not Found",
          message: "Post not found",
        });
      }

      const { title, body, tags, cover_image, status, published_at } = req.body;

      const validationErrors = validatePostInput(req.body, true);
      if (validationErrors.length > 0) {
        return res.status(400).json({
          error: "Validation Error",
          message: validationErrors.join("; "),
        });
      }

      let processedBody = typeof body === "string" ? body : post.body;
      let newSlug = post.slug;

      if (typeof body === "string" && title && title !== post.title) {
        const inferred = metadata.inferMetadata(title, processedBody);
        newSlug = metadata.generateUniqueSlugFromList(
          allPosts,
          inferred.slug,
          post.id,
        );
      }

      const updatedPost = {
        ...post,
        title: title || post.title,
        slug: newSlug,
        body: processedBody,
        cover_image:
          cover_image !== undefined ? cover_image || "" : post.cover_image,
        excerpt: body ? metadata.generateExcerpt(processedBody) : post.excerpt,
        keywords: body
          ? metadata.extractKeywords(title || post.title, processedBody)
          : post.keywords,
        meta_description: body
          ? metadata.generateMetaDescription(processedBody)
          : post.meta_description,
        reading_time: body
          ? metadata.calculateReadingTime(processedBody)
          : post.reading_time,
        status: status || post.status || "draft",
        tags: tags !== undefined ? tags : post.tags,
        published_at: published_at || post.published_at,
        updated_at: new Date().toISOString(),
      };

      await postsCollection.update(updatedPost.id, updatedPost);
      forceSavePost(updatedPost);

      ogImage.clearCache(post.slug);
      if (newSlug !== post.slug) {
        ogImage.clearCache(newSlug);
      }

      const apiJson = Post.fromDB(updatedPost).toApiJSON();
      apiJson.bodyHtml = await renderGistsInHtml(apiJson.bodyHtml);
      res.json(apiJson);
    } catch (err) {
      next(err);
    }
  }

  async executeDelete(slug) {
    const postsCollection = getCollection("posts");
    const allPosts = (await postsCollection.find()) || [];
    const post = allPosts.find((p) => p.slug === slug);

    if (!post) {
      const error = new Error("Post not found");
      error.status = 404;
      throw error;
    }

    await postsCollection.delete(post.id);
    ogImage.clearCache(slug);

    return {
      message: "Post deleted successfully",
      slug: slug,
    };
  }

  async delete(req, res, next) {
    try {
      const postsCollection = getCollection("posts");
      const allPosts = (await postsCollection.find()) || [];
      const post = allPosts.find((p) => p.slug === req.params.slug);

      if (!post) {
        return res.status(404).json({
          error: "Not Found",
          message: "Post not found",
        });
      }

      const token = await confirmationService.create("delete-post", {
        slug: req.params.slug,
      });
      const confirmationUrl = `/api/confirm/${token}`;

      res.status(202).json({
        confirmation_required: true,
        message:
          "This action requires confirmation. Please send a POST request to the confirmation_url to proceed.",
        confirmation_url: confirmationUrl,
      });
    } catch (err) {
      next(err);
    }
  }
}

export default new PostController();

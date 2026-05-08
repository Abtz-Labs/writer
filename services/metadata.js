function generateSlug(title) {
  if (!title) return '';
  
  let slug = title
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .trim();
  
  return slug;
}

function generateUniqueSlugFromList(posts, baseSlug, excludeId = null) {
  const existingSlugs = posts
    .filter(p => p.id !== excludeId)
    .map(p => p.slug);
  
  if (!existingSlugs.includes(baseSlug)) {
    return baseSlug;
  }
  
  let counter = 1;
  let slug = `${baseSlug}-${counter}`;
  
  while (existingSlugs.includes(slug)) {
    counter++;
    slug = `${baseSlug}-${counter}`;
  }
  
  return slug;
}

function extractKeywords(title, body) {
  const text = `${title} ${body}`.toLowerCase();
  
  const stopWords = new Set([
    'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
    'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
    'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
    'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need',
    'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we',
    'they', 'what', 'which', 'who', 'whom', 'whose', 'where', 'when', 'why',
    'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other',
    'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than',
    'too', 'very', 'just', 'into', 'over', 'after', 'before', 'between'
  ]);
  
  const words = text.match(/\b[a-z]{3,}\b/g) || [];
  const filtered = words.filter(w => !stopWords.has(w));
  
  const wordCount = {};
  filtered.forEach(word => {
    wordCount[word] = (wordCount[word] || 0) + 1;
  });
  
  const sorted = Object.entries(wordCount)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 10)
    .map(([word]) => word);
  
  return sorted.join(', ');
}

function generateMetaDescription(body) {
  if (!body) return '';
  
  const plainText = body
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/!\[.*?\]\(.*?\)/g, '')
    .replace(/\[.*?\]\(.*?\)/g, '')
    .replace(/~~[\s\S]+?~~/g, '')
    .replace(/~[\s\S]+?~/g, '')
    .replace(/[#*_`~]/g, '')
    .replace(/\n+/g, ' ')
    .trim();

  if (plainText.length <= 160) {
    return plainText;
  }

  return plainText.substring(0, 157).trim() + '...';
}

function calculateReadingTime(body) {
  if (!body) return 1;
  
  const words = body.trim().split(/\s+/).filter(w => w.length > 0).length;
  const minutes = Math.ceil(words / 200);
  
  return Math.max(1, minutes);
}

function generateExcerpt(body, maxLength = 200) {
  if (!body) return '';
  
  const plainText = body
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/!\[.*?\]\(.*?\)/g, '')
    .replace(/\[.*?\]\(.*?\)/g, '')
    .replace(/~~[\s\S]+?~~/g, '')
    .replace(/~[\s\S]+?~/g, '')
    .replace(/[#*_`~]/g, '')
    .replace(/\n+/g, ' ')
    .trim();

  if (plainText.length <= maxLength) {
    return plainText;
  }

  return plainText.substring(0, maxLength - 3).trim() + '...';
}

function inferMetadata(title, body) {
  const slug = generateSlug(title);
  const keywords = extractKeywords(title, body);
  const meta_description = generateMetaDescription(body);
  const reading_time = calculateReadingTime(body);
  const excerpt = generateExcerpt(body);
  
  return {
    slug,
    keywords,
    meta_description,
    reading_time,
    excerpt
  };
}

export {
  generateSlug,
  generateUniqueSlugFromList,
  extractKeywords,
  generateMetaDescription,
  calculateReadingTime,
  generateExcerpt,
  inferMetadata
};
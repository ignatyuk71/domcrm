const PLATFORM_META = Object.freeze({
  messenger: {
    code: 'messenger',
    label: 'Messenger',
    icon: 'bi bi-messenger',
  },
  instagram: {
    code: 'instagram',
    label: 'Instagram',
    icon: 'bi bi-instagram',
  },
});

const ORIGIN_TYPE_META = Object.freeze({
  direct: {
    type: 'direct',
    label: '',
    icon: '',
    threadTitle: 'Прямий діалог',
    sourceLine: null,
  },
  comment: {
    type: 'comment',
    label: 'Коментар',
    icon: 'bi bi-chat-left-text',
    threadTitle: 'Коментар',
    sourceLine: 'Коментар',
  },
  post: {
    type: 'post',
    label: 'Пост',
    icon: 'bi bi-file-post',
    threadTitle: 'Коментар до поста',
    sourceLine: 'Коментар до поста',
  },
  story: {
    type: 'story',
    label: 'Сторіс',
    icon: 'bi bi-clock-history',
    threadTitle: 'Відповідь на сторіс',
    sourceLine: 'Відповідь на сторіс',
  },
  reel: {
    type: 'reel',
    label: 'Reels',
    icon: 'bi bi-film',
    threadTitle: 'Коментар до Reels',
    sourceLine: 'Коментар до Reels',
  },
  ad: {
    type: 'ad',
    label: 'Реклама',
    icon: 'bi bi-badge-ad',
    threadTitle: 'Коментар до реклами',
    sourceLine: 'Коментар до реклами',
  },
});

const KNOWN_ORIGIN_TYPES = new Set(Object.keys(ORIGIN_TYPE_META));

export function resolveOriginContext(entity) {
  return entity?.origin_context || null;
}

export function resolveOriginType(originContext) {
  if (!originContext) {
    return 'direct';
  }

  const type = String(originContext.object_type || '').trim().toLowerCase();
  if (KNOWN_ORIGIN_TYPES.has(type) && type !== 'direct') {
    return type;
  }

  if (String(originContext.kind || '').trim().toLowerCase() === 'comment') {
    return 'comment';
  }

  return 'direct';
}

export function resolveOriginMeta(originContext) {
  return ORIGIN_TYPE_META[resolveOriginType(originContext)] || ORIGIN_TYPE_META.direct;
}

export function resolveOriginPlatform(originContext, fallbackPlatform = 'messenger') {
  const platform = String(originContext?.platform || fallbackPlatform || '').trim().toLowerCase();

  return PLATFORM_META[platform] ? platform : 'messenger';
}

export function resolvePlatformMeta(platform) {
  return PLATFORM_META[String(platform || '').trim().toLowerCase()] || PLATFORM_META.messenger;
}

export function resolveOriginBadgeClass(originContext, prefix = 'origin') {
  const type = resolveOriginType(originContext);

  return `${prefix}-${type}`;
}

export function resolveOriginSummaryLine(originContext, fallbackPlatform = 'messenger') {
  if (!originContext) {
    return '';
  }

  const originMeta = resolveOriginMeta(originContext);
  const platformMeta = resolvePlatformMeta(resolveOriginPlatform(originContext, fallbackPlatform));
  const summary = String(originContext.summary || '').trim();

  if (summary) {
    return summary;
  }

  if (originMeta.sourceLine) {
    return `${originMeta.sourceLine} · ${platformMeta.label}`;
  }

  return platformMeta.label;
}

export function resolveOriginTitle(originContext) {
  return resolveOriginMeta(originContext).threadTitle;
}

export function isCommentThread(chat) {
  const originContext = resolveOriginContext(chat);
  const kind = String(chat?.thread_kind || originContext?.kind || '').trim().toLowerCase();

  return kind === 'comment' || resolveOriginType(originContext) !== 'direct';
}

export function matchConversationByTab(chat, tab) {
  if (tab === 'all') {
    return true;
  }

  if (tab === 'messenger' || tab === 'instagram') {
    return chat?.platform === tab && !isCommentThread(chat);
  }

  const commentThread = isCommentThread(chat);
  if (!commentThread) {
    return false;
  }

  const originPlatform = resolveOriginPlatform(resolveOriginContext(chat), chat?.platform || 'messenger');

  if (tab === 'facebook_comments') {
    return originPlatform === 'messenger';
  }

  if (tab === 'instagram_comments') {
    return originPlatform === 'instagram';
  }

  return true;
}

import { connectMongoDB } from './infrastructure/database/mongodb/connection.js';
import { connectPrisma } from './infrastructure/database/prisma/prisma.client.js';
import { ConversationPrismaRepository } from './infrastructure/database/prisma/repositories/conversation.prisma-repository.js';
import { MessagePrismaRepository } from './infrastructure/database/prisma/repositories/message.prisma-repository.js';
import { AgentPrismaRepository } from './infrastructure/database/prisma/repositories/agent.prisma-repository.js';
import { QuickReplyPrismaRepository } from './infrastructure/database/prisma/repositories/quick-reply.prisma-repository.js';
import { FunnelUserPrismaRepository } from './infrastructure/database/prisma/repositories/funnel-user.prisma-repository.js';
import { UserPrismaRepository } from './infrastructure/database/prisma/repositories/user.prisma-repository.js';
import { UserMongoRepository } from './infrastructure/database/mongodb/repositories/user.mongo-repository.js';
import { ProgramMongoRepository } from './infrastructure/database/mongodb/repositories/program.mongo-repository.js';
import { PromptMongoRepository } from './infrastructure/database/mongodb/repositories/prompt.mongo-repository.js';
import { FunnelIntentionMongoRepository } from './infrastructure/database/mongodb/repositories/funnel-intention.mongo-repository.js';
import { ContextSourceDataMongoRepository } from './infrastructure/database/mongodb/repositories/context-source-data.mongo-repository.js';
import { FacultyMongoRepository } from './infrastructure/database/mongodb/repositories/faculty.mongo-repository.js';
import { FunnelMessageMongoRepository } from './infrastructure/database/mongodb/repositories/funnel-message.mongo-repository.js';
import { NoOpFunnelMessageRepository } from './infrastructure/database/noop-funnel-message.repository.js';
import { DeepSeekAdapter } from './infrastructure/ai/deepseek/deepseek.adapter.js';
import { loadDeepSeekConfig } from './infrastructure/ai/deepseek/deepseek.config.js';
import { TemplateService } from './infrastructure/ai/template/template.service.js';
import { MetaWhatsAppAdapter } from './infrastructure/webhooks/meta/meta-whatsapp.adapter.js';
import { MetaMediaService } from './infrastructure/webhooks/meta/meta-media.service.js';
import { LocalMediaStorage } from './infrastructure/storage/local-media.storage.js';
import { WhatsAppController } from './infrastructure/webhooks/meta/whatsapp.controller.js';
import { WhatsAppParserService } from './infrastructure/webhooks/meta/whatsapp-parser.service.js';
import { HandleIncomingMessageUseCase } from './application/use-cases/handle-incoming-message/handle-incoming-message.usecase.js';
import { SendProgramBrochureUseCase } from './application/use-cases/send-program-brochure/send-program-brochure.usecase.js';
import { HandleMessageStatusUseCase } from './application/use-cases/handle-message-status/handle-message-status.usecase.js';
import { SystemPromptBuilderService } from './application/services/system-prompt-builder.service.js';
import { IntentRouterService } from './application/services/intent-router.service.js';
import { RealtimeNotifier } from './application/services/realtime-notifier.service.js';
import { createWebhookRouter } from './infrastructure/http/routes/webhook.routes.js';
import { createAuthRouter } from './infrastructure/http/routes/auth.routes.js';
import { createAgentInboxRouter } from './infrastructure/http/routes/agent-inbox.routes.js';
import { createQuickRepliesRouter } from './infrastructure/http/routes/quick-replies.routes.js';
import { EnrollmentPolicyMongoRepository } from './infrastructure/database/mongodb/repositories/enrollment-policy.mongo-repository.js';
import { CurriculumVersionMongoRepository } from './infrastructure/database/mongodb/repositories/curriculum-version.mongo-repository.js';
import { ProductToolsService } from './infrastructure/ai/tools/product-tools.service.js';
import { loadKnowledgeBase, resolveKnowledgeBasePath } from './infrastructure/ai/knowledge/knowledge-base.loader.js';
import { ChatSessionStore } from './infrastructure/ai/chat-session.store.js';
import { HybridChatService } from './application/services/hybrid-chat.service.js';
import {
  MessageBatchDebouncer,
  loadMessageDebounceMs,
} from './application/services/message-batch-debouncer.service.js';
import { ChatController } from './infrastructure/http/controllers/chat.controller.js';
import { createChatRouter } from './infrastructure/http/routes/chat.routes.js';
import { createServer } from './infrastructure/http/server.js';
import { WebSocketRealtimeAdapter } from './infrastructure/realtime/websocket-realtime.adapter.js';
import { logger } from './infrastructure/shared/logger.js';

async function bootstrap(): Promise<void> {
  await connectPrisma();

  const mongoUri = process.env['MONGODB_URI'];
  if (mongoUri) {
    await connectMongoDB({
      uri: mongoUri,
      dbName: process.env['MONGODB_DB_NAME'] ?? 'chatbot_uprit',
      maxPoolSize: Number(process.env['MONGODB_MAX_POOL_SIZE'] ?? 10),
      minPoolSize: Number(process.env['MONGODB_MIN_POOL_SIZE'] ?? 2),
    });
  } else {
    logger.warn('[Bootstrap] MONGODB_URI not set — MongoDB repositories disabled');
  }

  const jwtSecret = process.env['JWT_SECRET'];
  if (!jwtSecret) throw new Error('JWT_SECRET environment variable is required');

  if (process.env['ERP_SSO_ONLY'] === 'true' && !process.env['ERP_JWT_SECRET']) {
    throw new Error('ERP_JWT_SECRET environment variable is required when ERP_SSO_ONLY=true');
  }

  // ── Repositories ──────────────────────────────────────────────────────────
  const conversationRepo = new ConversationPrismaRepository();
  const messageRepo = new MessagePrismaRepository();
  const agentRepo = new AgentPrismaRepository();
  const funnelUserRepo = new FunnelUserPrismaRepository();
  const quickReplyRepo = new QuickReplyPrismaRepository();

  const userRepo = mongoUri ? new UserMongoRepository() : new UserPrismaRepository();

  const programRepo = mongoUri ? new ProgramMongoRepository() : undefined;
  const promptRepo = mongoUri ? new PromptMongoRepository() : undefined;
  const funnelIntentionRepo = mongoUri ? new FunnelIntentionMongoRepository() : undefined;
  const contextSourceRepo = mongoUri ? new ContextSourceDataMongoRepository() : undefined;
  const facultyRepo = mongoUri ? new FacultyMongoRepository() : undefined;
  const funnelMessageRepo = mongoUri
    ? new FunnelMessageMongoRepository()
    : new NoOpFunnelMessageRepository();
  const enrollmentPolicyRepo = mongoUri ? new EnrollmentPolicyMongoRepository() : undefined;
  const curriculumVersionRepo = mongoUri ? new CurriculumVersionMongoRepository() : undefined;

  // ── AI ────────────────────────────────────────────────────────────────────
  const deepSeekConfig = loadDeepSeekConfig();
  const templateService = new TemplateService();
  const deepSeekAdapter = new DeepSeekAdapter(deepSeekConfig);
  logger.info('[Bootstrap] AI engine initialized', { model: deepSeekConfig.model });

  // ── Hybrid chat engine (DeepSeek tool calling + MongoDB, static KB from context/knowledge_base.md) ──
  const productToolsService = new ProductToolsService();
  const knowledgeBase = loadKnowledgeBase(resolveKnowledgeBasePath());
  const hybridChatService = new HybridChatService(deepSeekAdapter, productToolsService, knowledgeBase);
  const chatSessionStore = new ChatSessionStore();
  const chatController = new ChatController(hybridChatService, chatSessionStore);
  logger.info('[Bootstrap] Hybrid chat engine initialized', { knowledgeBaseChars: knowledgeBase.length });

  const messageDebounceMs = loadMessageDebounceMs();
  const messageDebouncer = new MessageBatchDebouncer(messageDebounceMs);
  logger.info('[Bootstrap] Message batch debounce configured', {
    delayMs: messageDebounceMs,
    enabled: messageDebouncer.enabled,
  });

  const promptBuilder = new SystemPromptBuilderService();

  // ── Messaging provider + Media ────────────────────────────────────────────
  const metaMediaConfig = {
    token: process.env['META_WHATSAPP_TOKEN'] ?? '',
    phoneNumberId: process.env['META_WHATSAPP_PHONE_NUMBER_ID'] ?? '',
    apiVersion: process.env['META_API_VERSION'] ?? 'v20.0',
    baseUrl: process.env['META_API_BASE_URL'] ?? 'https://graph.facebook.com',
  };
  const metaMediaService = new MetaMediaService(metaMediaConfig);
  const localMediaStorage = new LocalMediaStorage(
    process.env['MEDIA_STORAGE_PATH'] ?? '/app/uploads',
  );
  const metaAdapter = new MetaWhatsAppAdapter(metaMediaConfig, metaMediaService);

  const intentRouter =
    mongoUri && programRepo && promptRepo && funnelIntentionRepo && contextSourceRepo
      ? new IntentRouterService(
          deepSeekAdapter,
          programRepo,
          promptRepo,
          funnelIntentionRepo,
          contextSourceRepo,
          facultyRepo,
          productToolsService,
          knowledgeBase,
        )
      : undefined;

  const sendProgramBrochure =
    programRepo
      ? new SendProgramBrochureUseCase(programRepo, metaAdapter, metaMediaService)
      : undefined;

  logger.info('[Bootstrap] Media storage initialized', {
    path: process.env['MEDIA_STORAGE_PATH'] ?? '/app/uploads',
  });

  // ── Realtime: create adapter first (no httpServer yet), then notifier ─────
  const realtimeAdapter = new WebSocketRealtimeAdapter({
    jwtSecret,
    heartbeatMs: Number(process.env['WS_HEARTBEAT_MS'] ?? 30_000),
  });
  const realtimeNotifier = new RealtimeNotifier(realtimeAdapter);

  // ── Use cases ─────────────────────────────────────────────────────────────
  const handleIncomingMessage = new HandleIncomingMessageUseCase(
    conversationRepo,
    userRepo,
    deepSeekAdapter,
    metaAdapter,
    programRepo,
    promptBuilder,
    agentRepo,
    intentRouter,
    funnelUserRepo,
    funnelMessageRepo,
    messageRepo,
    realtimeNotifier,
    metaMediaService,
    localMediaStorage,
    sendProgramBrochure,
    hybridChatService,
    productToolsService,
    knowledgeBase,
    messageDebouncer,
  );

  const handleMessageStatus = new HandleMessageStatusUseCase(
    messageRepo,
    conversationRepo,
    realtimeNotifier,
  );

  // ── HTTP + WebSocket ───────────────────────────────────────────────────────
  const whatsAppParser = new WhatsAppParserService();
  const whatsAppController = new WhatsAppController(
    whatsAppParser,
    handleIncomingMessage,
    handleMessageStatus,
    process.env['META_WEBHOOK_VERIFY_TOKEN'] ?? '',
  );

  const webhookRouter = createWebhookRouter(whatsAppController);
  const authRouter = createAuthRouter(agentRepo);
  const agentInboxRouter = createAgentInboxRouter(
    conversationRepo,
    userRepo,
    funnelUserRepo,
    agentRepo,
    metaAdapter,
    funnelMessageRepo,
    metaMediaService,
    localMediaStorage,
    realtimeNotifier,
    messageRepo,
  );
  const quickRepliesRouter = createQuickRepliesRouter(quickReplyRepo);
  const chatRouter = createChatRouter(chatController);

  const corsOrigins = [
    ...(process.env['CORS_ORIGINS'] ?? '').split(',').filter(Boolean),
    ...(process.env['ADMIN_CORS_ORIGIN'] ? [process.env['ADMIN_CORS_ORIGIN']] : []),
    'http://localhost:5173',
  ];

  const { httpServer } = createServer(webhookRouter, authRouter, agentInboxRouter, {
    port: Number(process.env['PORT'] ?? 3000),
    corsOrigins: [...new Set(corsOrigins)],
    mediaStoragePath: process.env['MEDIA_STORAGE_PATH'] ?? '/app/uploads',
  }, quickRepliesRouter, chatRouter);

  // start() receives httpServer now that it's ready
  realtimeAdapter.start(httpServer);
}

bootstrap().catch((err: unknown) => {
  logger.error('[Bootstrap] Fatal error starting application', { error: err });
  process.exit(1);
});

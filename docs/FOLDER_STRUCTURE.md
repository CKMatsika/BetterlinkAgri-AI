# BetterlinkAgr AI — Project Folder & Module Structure

The codebase follows **Laravel 11** conventions with strict **Domain-Driven Service & Action layers** (PSR-12, constructor dependency injection, thin controllers, immutable DTOs, explicit `ConversationStateMachine`, and strict tenant + outlet scoping).

```text
betterlink-agr-ai/
├── .env.example                                  # Complete environment configuration template
├── .github/
│   └── workflows/
│       ├── ci.yml                                # PHPStan, Laravel Pint (PSR-12), Pest + PostGIS/pgvector tests
│       └── deploy.yml                            # Docker build, migration check & zero-downtime deploy
├── docker/
│   ├── nginx/
│   │   └── default.conf                          # Nginx reverse proxy & webhook rate-limiting config
│   ├── php/
│   │   ├── Dockerfile                            # PHP 8.3-FPM + gd/imagick + exif + pdo_pgsql + redis
│   │   └── php.ini                               # Upload limits, OPcache & memory settings
│   └── postgres/
│       └── init-extensions.sql                   # CREATE EXTENSION vector; CREATE EXTENSION postgis;
├── docker-compose.yml                            # App, Horizon, Scheduler, Postgres 16 (pgvector+PostGIS), Redis 7, MinIO
├── docs/
│   ├── ARCHITECTURE.md                           # System architecture, supply network & Zimbabwe domain spec
│   ├── ERD.md                                    # Mermaid ER diagram & 42-table schema specification
│   ├── DIAGNOSIS_STATE_MACHINE.md                # Authoritative 24-state machine diagram, guards & implementation plan
│   ├── FOLDER_STRUCTURE.md                       # Annotated repository structure
│   └── openapi.yaml                              # OpenAPI 3.1 specification for /api/v1
├── app/
│   ├── Actions/                                  # Single-responsibility invokable domain actions
│   │   ├── Consent/
│   │   │   ├── RecordFarmerConsentAction.php
│   │   │   ├── RecordLocationConsentAction.php
│   │   │   ├── ProcessOptOutOrOptInAction.php
│   │   │   └── ExportOrEraseFarmerDataAction.php
│   │   ├── Diagnosis/
│   │   │   ├── CreateIntakeDiagnosisAction.php
│   │   │   ├── RecordQuestionAnswerAction.php
│   │   │   ├── FinalizeAdvisoryAction.php
│   │   │   └── EscalateToAgronomistAction.php
│   │   ├── Outlets/
│   │   │   ├── CreateStockReservationAction.php
│   │   │   ├── RespondToReservationAction.php
│   │   │   ├── ParseWhatsAppStockUpdateAction.php
│   │   │   └── RecordSalesAttributionAction.php
│   │   ├── Leads/
│   │   │   ├── ScoreAndCreateLeadAction.php
│   │   │   ├── AssignLeadToRepOrOutletAction.php
│   │   │   ├── ScheduleRepMeetingAction.php
│   │   │   └── TriggerLiveChatTakeoverAction.php
│   │   └── Rag/
│   │       ├── IngestProductDocumentAction.php
│   │       └── ChunkAndEmbedPdfAction.php
│   ├── Console/
│   │   └── Commands/
│   │       ├── CheckReservationAndLeadSlasCommand.php
│   │       ├── SendStaleOutletStockRemindersCommand.php
│   │       ├── DispatchScheduledFollowUpsCommand.php
│   │       ├── DispatchProgrammeAndEventRemindersCommand.php
│   │       └── DetectWardOutbreaksCommand.php
│   ├── Contracts/                                # Swappable interfaces (Dependency Inversion)
│   │   ├── AiProviderContract.php
│   │   ├── AiToolContract.php
│   │   ├── EmbeddingProviderContract.php
│   │   ├── VoiceTranscriberContract.php
│   │   ├── MessagingChannelContract.php
│   │   ├── SmsUssdGatewayContract.php
│   │   ├── WeatherProviderContract.php
│   │   └── CrmAdapterContract.php
│   ├── DTOs/                                     # Strictly-typed readonly Data Transfer Objects
│   │   ├── InboundMessageData.php
│   │   ├── ImageQualityReportData.php
│   │   ├── VisionIdentificationResultData.php
│   │   ├── CandidateDiagnosisData.php
│   │   ├── ClarifyingQuestionData.php
│   │   ├── ApplicationRateCalculationData.php
│   │   ├── MatchedProductRecommendationData.php
│   │   ├── RankedOutletResultData.php
│   │   ├── SprayingWindowData.php
│   │   ├── StructuredAdvisoryData.php
│   │   ├── RichMessageCardData.php
│   │   └── LeadQualificationData.php
│   ├── Enums/                                    # PHP 8.3 backed enums
│   │   ├── TenantType.php                        # manufacturer | distributor | agro_dealer
│   │   ├── RecommendationScope.php               # own_brands_only | own_plus_partner_brands
│   │   ├── ConversationState.php                 # All 24 states from the authoritative State Machine
│   │   ├── ConversationMode.php                  # ai_active | human_takeover
│   │   ├── DiagnosisCategory.php
│   │   ├── RichCardType.php                      # diagnosis_result | recommended_product | outlet_list | etc.
│   │   ├── StockQuantityBand.php                 # in_stock | low_stock | out_of_stock
│   │   ├── FarmingSector.php
│   │   ├── NaturalRegion.php
│   │   ├── LanguageCode.php                      # en | sn | nd
│   │   ├── CurrencyCode.php                      # USD | ZIG
│   │   ├── LeadTemperature.php                   # cold | warm | hot
│   │   ├── HandoffType.php
│   │   └── UserRole.php                          # super_admin | tenant_admin | agronomist | sales_rep | outlet_manager
│   ├── Filament/                                 # Three Filament v3 Panels (Phase 4 & 6)
│   │   ├── Platform/                             # 1. Platform Admin Panel (/platform)
│   │   │   ├── Resources/
│   │   │   │   ├── TenantResource.php
│   │   │   │   ├── TenantSupplyLinkResource.php
│   │   │   │   └── GeographyWardResource.php
│   │   │   └── Widgets/
│   │   │       └── PlatformSellThroughWidget.php
│   │   ├── Tenant/                               # 2. Tenant Admin Panel (/admin/{tenant})
│   │   │   ├── Components/
│   │   │   │   └── ConnectedFarmerProfilePanel.php
│   │   │   ├── Pages/
│   │   │   │   ├── RecommendationBoundariesEditor.php
│   │   │   │   └── LiveConversationTakeoverPage.php
│   │   │   ├── Resources/
│   │   │   │   ├── ProductResource.php
│   │   │   │   ├── ProductDocumentResource.php
│   │   │   │   ├── FertiliserRecommendationResource.php
│   │   │   │   ├── OutletResource.php
│   │   │   │   ├── TenantSupplyLinkResource.php
│   │   │   │   ├── DiagnosisReviewQueueResource.php
│   │   │   │   ├── ConversationAuditResource.php
│   │   │   │   ├── ProgrammeResource.php
│   │   │   │   ├── CampaignResource.php
│   │   │   │   ├── EventResource.php
│   │   │   │   ├── LeadResource.php
│   │   │   │   └── FarmerResource.php
│   │   │   └── Widgets/
│   │   │       ├── OverviewKpiStatsWidget.php
│   │   │       ├── DiagnosesByCropAndDistrictChart.php
│   │   │       ├── WardOutbreakAlertMapWidget.php
│   │   │       ├── DemandVsStockCoverageWidget.php
│   │   │       ├── LeadFunnelAndSalesAttributionWidget.php
│   │   │       └── AiAccuracyAndSatisfactionWidget.php
│   │   └── Outlet/                               # 3. Outlet Portal Panel (/outlet - Mobile-First)
│   │       ├── Pages/
│   │       │   └── QuickStockBandsUpdaterPage.php
│   │       ├── Resources/
│   │       │   ├── OutletReservationResource.php
│   │       │   ├── OutletStockResource.php
│   │       │   └── OutletSalesAttributionResource.php
│   │       └── Widgets/
│   │           └── OutletSlaAndFreshnessWidget.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       └── V1/
│   │   │           ├── WhatsAppWebhookController.php
│   │   │           ├── SmsUssdWebhookController.php
│   │   │           ├── WebChatController.php
│   │   │           ├── OutletPortalApiController.php
│   │   │           ├── DiagnosisApiController.php
│   │   │           ├── LeadCrmWebhookController.php
│   │   │           └── FarmerPrivacyController.php
│   │   ├── Middleware/
│   │   │   ├── VerifyWhatsAppSignature.php
│   │   │   ├── EnforceWhatsAppIdempotency.php
│   │   │   ├── ResolveTenantFromRequest.php
│   │   │   └── EnsureOutletManagerScope.php
│   │   ├── Requests/
│   │   │   └── Api/V1/
│   │   │       ├── WebChatMessageRequest.php
│   │   │       ├── OutletStockUpdateRequest.php
│   │   │       ├── ReservationActionRequest.php
│   │   │       └── CrmLeadUpdateRequest.php
│   │   └── Resources/
│   │       └── Api/V1/
│   │           ├── ConversationResource.php
│   │           ├── RichMessageCardResource.php
│   │           ├── DiagnosisResource.php
│   │           ├── AdvisoryResource.php
│   │           ├── OutletResultResource.php
│   │           └── LeadResource.php
│   ├── Jobs/                                     # Redis / Horizon queued background jobs
│   │   ├── ProcessInboundWhatsAppMessageJob.php
│   │   ├── DownloadAndProcessWhatsAppMediaJob.php
│   │   ├── RunConversationStateTurnJob.php
│   │   ├── SendOutboundChannelMessageJob.php
│   │   ├── IngestProductDocumentJob.php
│   │   ├── CheckReservationSlaJob.php
│   │   ├── SyncLeadToExternalCrmJob.php
│   │   └── SendFollowUpCheckInJob.php
│   ├── Models/                                   # 42 Eloquent Models (UUIDs, SoftDeletes, Scopes, Encrypted PII)
│   │   ├── Concerns/
│   │   │   ├── BelongsToTenant.php
│   │   │   ├── BelongsToOutlet.php
│   │   │   └── HasEncryptedPii.php
│   │   ├── Scopes/
│   │   │   ├── TenantScope.php
│   │   │   └── OutletScope.php
│   │   ├── Tenant.php
│   │   ├── TenantSupplyLink.php
│   │   ├── User.php
│   │   ├── Province.php
│   │   ├── District.php
│   │   ├── Ward.php
│   │   ├── WardCentroid.php
│   │   ├── Farmer.php
│   │   ├── Consent.php
│   │   ├── LocationConsent.php
│   │   ├── FarmerLocation.php
│   │   ├── Farm.php
│   │   ├── Crop.php
│   │   ├── Livestock.php
│   │   ├── Conversation.php
│   │   ├── Message.php
│   │   ├── Attachment.php
│   │   ├── Diagnosis.php
│   │   ├── DiagnosisQuestion.php
│   │   ├── Advisory.php
│   │   ├── Product.php
│   │   ├── ProductDocument.php
│   │   ├── ProductTarget.php
│   │   ├── FertiliserRecommendation.php
│   │   ├── KnowledgeChunk.php
│   │   ├── Outlet.php
│   │   ├── OutletUser.php
│   │   ├── OutletStock.php
│   │   ├── OutletProductAuthorisation.php
│   │   ├── Reservation.php
│   │   ├── SalesAttribution.php
│   │   ├── Programme.php
│   │   ├── ProgrammeEnrolment.php
│   │   ├── Campaign.php
│   │   ├── CampaignRecipient.php
│   │   ├── Event.php
│   │   ├── EventRsvp.php
│   │   ├── OutbreakSignal.php
│   │   ├── Lead.php
│   │   ├── LeadActivity.php
│   │   ├── Handoff.php
│   │   ├── Meeting.php
│   │   ├── FollowUp.php
│   │   ├── WeatherSnapshot.php
│   │   └── AuditLog.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   ├── HorizonServiceProvider.php
│   │   └── Filament/
│   │       ├── PlatformPanelProvider.php
│   │       ├── TenantPanelProvider.php
│   │       └── OutletPanelProvider.php
│   └── Services/                                 # Core business & AI orchestration services
│       ├── Ai/
│       │   ├── AiAgentService.php                # Swappable Claude Vision + 13 Tool Calling orchestrator
│       │   ├── Providers/
│       │   │   └── AnthropicClaudeProvider.php
│       │   ├── Embeddings/
│       │   │   ├── VoyageEmbeddingProvider.php
│       │   │   └── OpenAiEmbeddingProvider.php
│       │   ├── Tools/                            # All 13 Structured Tool Definitions & Deterministic Executors
│       │   │   ├── IdentifyFromImageTool.php
│       │   │   ├── SearchKnowledgeTool.php
│       │   │   ├── LookupProductsTool.php
│       │   │   ├── CalculateApplicationRateTool.php
│       │   │   ├── GetWeatherTool.php
│       │   │   ├── UpdateFarmerProfileTool.php
│       │   │   ├── FindNearestOutletsTool.php
│       │   │   ├── CheckOutletStockTool.php
│       │   │   ├── CreateReservationTool.php
│       │   │   ├── RequestFarmerLocationTool.php
│       │   │   ├── CreateLeadTool.php
│       │   │   ├── HandoffToHumanTool.php
│       │   │   └── ScheduleMeetingTool.php
│       │   └── Voice/
│       │       └── VoiceTranscriptionService.php
│       ├── Channels/
│       │   ├── WhatsAppCloudService.php
│       │   ├── SmsUssdGatewayService.php
│       │   └── RichCardRendererService.php       # Renders 7 rich card types for WhatsApp & Web/Flutter
│       ├── Crm/
│       │   ├── CrmManager.php
│       │   └── Adapters/
│       │       ├── StandaloneDatabaseAdapter.php
│       │       ├── CsvExportImportAdapter.php
│       │       ├── SignedWebhookCrmAdapter.php
│       │       ├── HubSpotCrmAdapter.php
│       │       └── ZohoCrmAdapter.php
│       ├── Diagnosis/
│       │   ├── ConversationStateMachine.php      # Explicit 24-state engine with guards & audit logging
│       │   ├── IntentRouterService.php
│       │   ├── MediaIntakeService.php
│       │   ├── VisionDiagnosisService.php
│       │   ├── AdaptiveQuestionEngine.php
│       │   ├── QuestionBankRepository.php
│       │   ├── ProductMatcherService.php
│       │   ├── ApplicationRateCalculator.php
│       │   ├── AdvisoryGeneratorService.php
│       │   ├── SafetyGuardrailValidator.php
│       │   └── OutbreakDetectionService.php
│       ├── Engagement/
│       │   ├── FarmerProfilingService.php
│       │   ├── ProgrammeEngineService.php
│       │   ├── CampaignBroadcastService.php
│       │   └── EventRsvpService.php
│       ├── Leads/
│       │   ├── LeadQualificationService.php
│       │   ├── HandoffRoutingService.php
│       │   └── MeetingSchedulerService.php       # Slot proposal, .ics file generation, WhatsApp reminders
│       ├── Localization/
│       │   └── ZimbabweAgronomyGlossary.php      # EN / Shona (sn) / Ndebele (nd) pest, crop & nutrient glossary
│       ├── Outlets/
│       │   ├── LocationResolutionService.php     # Pin -> Saved Farm -> Ward Centroid + privacy guard
│       │   ├── OutletFinderService.php           # Deterministic PostGIS 25->50->100km->Province ranker
│       │   ├── OutletStockService.php            # 48h freshness check, bands, WhatsApp parser
│       │   └── ReservationService.php            # 30m SLA hold, cascade to next outlet, attribution
│       ├── Rag/
│       │   └── TenantRagService.php              # pgvector HNSW cosine search scoped to tenant + supply links
│       └── Weather/
│           └── ZimbabweWeatherService.php
├── config/
│   ├── app.php
│   ├── betterlink.php                            # State thresholds, Pfumvudza, outlet weights, SLA config
│   ├── database.php
│   ├── horizon.php
│   └── services.php
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_enable_pgvector_and_postgis_extensions.php
│   │   ├── 2026_01_01_000001_create_tenants_supply_links_and_geography_tables.php
│   │   ├── 2026_01_01_000002_create_users_farmers_consents_and_locations_tables.php
│   │   ├── 2026_01_01_000003_create_crops_livestock_and_farms_tables.php
│   │   ├── 2026_01_01_000004_create_conversations_messages_and_attachments_tables.php
│   │   ├── 2026_01_01_000005_create_diagnoses_questions_and_advisories_tables.php
│   │   ├── 2026_01_01_000006_create_products_targets_fertilisers_and_rag_tables.php
│   │   ├── 2026_01_01_000007_create_outlets_stock_authorisations_and_reservations_tables.php
│   │   └── 2026_01_01_000008_create_programmes_campaigns_events_leads_and_audit_tables.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── ZimbabweGeographyAndCentroidsSeeder.php # Provinces, Districts, Wards, Natural Regions & PostGIS Centroids
│       ├── ZimbabweCropsAndLivestockSeeder.php     # 14 priority crops & 3 livestock categories (EN/SN/ND)
│       ├── SampleTenantsAndOutletsSeeder.php       # Manufacturer, Distributor & Agro-Dealer + PostGIS Outlets
│       ├── SampleProductCatalogueSeeder.php        # Registered ZW products, doses, PHI/REI, Compound D/AN/Lime
│       └── ZimbabweKnowledgeBaseSeeder.php         # Fall armyworm, stalk borer, Tuta, GLS, N/P/K/S/Zn/B guides
├── public/
│   └── widget/
│       ├── betterlink-chat-widget.js             # Embeddable Web Chat Widget with 7 Rich Card types & Context Chip
│       └── betterlink-chat-widget.css
├── routes/
│   ├── api.php                                   # /api/v1 versioned routes
│   ├── console.php                               # Scheduled SLA, stale stock, follow-up & outbreak tasks
│   └── web.php
├── tests/
│   ├── Pest.php
│   ├── Fixtures/
│   │   ├── Images/                               # Sample test images (clear leaf, blurry leaf, dark leaf)
│   │   └── Payloads/                             # Sample WhatsApp webhook & Claude Vision JSON fixtures
│   ├── Feature/
│   │   ├── ConversationStateMachineTest.php
│   │   ├── TenantAndOutletIsolationTest.php
│   │   ├── WhatsAppWebhookAndConsentTest.php
│   │   ├── DiagnosisEngineFlowTest.php
│   │   ├── SafetyGuardrailEnforcementTest.php
│   │   ├── OutletFinderAndReservationSlaTest.php
│   │   └── LeadQualificationAndMeetingTest.php
│   └── Unit/
│       ├── ApplicationRateCalculatorTest.php
│       ├── AdaptiveQuestionEngineTest.php
│       ├── MediaIntakeQualityTest.php
│       └── ProductMatcherServiceTest.php
└── mobile_app/                                   # Optional Phase 6 Flutter App (Rep & Offline Farmer Mode)
    ├── pubspec.yaml
    └── lib/
```

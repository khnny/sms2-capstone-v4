-- SMS2/CRAD deployment schema, 14 October 2026.
-- Import into an empty selected hosting database.
-- SMS2 preserves existing accounts; CRAD contains schema only.
SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;

-- Table: crad_chapter_evaluation_notifications
CREATE TABLE `crad_chapter_evaluation_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(120) NOT NULL,
  `recipient_user_id` int(10) unsigned DEFAULT NULL,
  `recipient_role` varchar(60) NOT NULL DEFAULT '',
  `recipient_email` varchar(190) NOT NULL DEFAULT '',
  `submission_id` int(10) unsigned NOT NULL,
  `type` varchar(60) NOT NULL,
  `title` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chapter_notification_event` (`event_key`),
  KEY `idx_chapter_notification_recipient` (`recipient_user_id`,`recipient_role`,`recipient_email`),
  KEY `idx_chapter_notification_submission` (`submission_id`),
  KEY `idx_chapter_notification_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_chapter_evaluations
CREATE TABLE `crad_chapter_evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned NOT NULL,
  `evaluator_user_id` int(10) unsigned NOT NULL,
  `evaluator_name` varchar(150) NOT NULL DEFAULT '',
  `content_score` decimal(5,2) NOT NULL,
  `methodology_score` decimal(5,2) NOT NULL,
  `references_score` decimal(5,2) NOT NULL,
  `format_score` decimal(5,2) NOT NULL,
  `grammar_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `content_remarks` text DEFAULT NULL,
  `methodology_remarks` text DEFAULT NULL,
  `references_remarks` text DEFAULT NULL,
  `format_remarks` text DEFAULT NULL,
  `grammar_remarks` text DEFAULT NULL,
  `overall_feedback` text DEFAULT NULL,
  `result` enum('APPROVED','APPROVED WITH REVISION') NOT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `evaluated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chapter_evaluation_submission` (`submission_id`),
  KEY `idx_chapter_eval_evaluator` (`evaluator_user_id`),
  KEY `idx_chapter_eval_group` (`research_group_id`),
  KEY `idx_chapter_eval_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_chapter_submission_history
CREATE TABLE `crad_chapter_submission_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned NOT NULL,
  `chapter_number` tinyint(3) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `status` varchar(40) NOT NULL,
  `event_type` varchar(60) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_name` varchar(150) NOT NULL DEFAULT '',
  `actor_role` varchar(60) NOT NULL DEFAULT '',
  `detail` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chapter_history_submission` (`submission_id`),
  KEY `idx_chapter_history_group` (`research_group_id`),
  KEY `idx_chapter_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_chapter_submissions
CREATE TABLE `crad_chapter_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `research_plan_id` int(10) unsigned DEFAULT NULL,
  `chapter_number` tinyint(3) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `status` enum('Submitted','Under Review','Needs Revision','Accepted') NOT NULL DEFAULT 'Submitted',
  `submitted_by_user` int(10) unsigned DEFAULT NULL,
  `submitted_by_name` varchar(150) NOT NULL DEFAULT '',
  `submitted_by_email` varchar(190) NOT NULL DEFAULT '',
  `submission_notes` text DEFAULT NULL,
  `original_name` varchar(255) NOT NULL DEFAULT '',
  `stored_subdir` varchar(180) NOT NULL DEFAULT '',
  `stored_name` varchar(120) NOT NULL DEFAULT '',
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `file_mime` varchar(120) NOT NULL DEFAULT '',
  `submission_token` varchar(64) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `review_started_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chapter_version` (`research_group_id`,`chapter_number`,`version_number`),
  UNIQUE KEY `uniq_chapter_token` (`submission_token`),
  KEY `idx_chapter_status` (`status`),
  KEY `idx_chapter_group` (`research_group_id`),
  KEY `idx_chapter_student` (`submitted_by_user`),
  KEY `idx_chapter_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_defense_panel_request_documents
CREATE TABLE `crad_defense_panel_request_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `doc_type` varchar(40) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(120) NOT NULL,
  `mime` varchar(60) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_panel_app_doc_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_defense_panel_request_history
CREATE TABLE `crad_defense_panel_request_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_name` varchar(160) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_panel_app_history_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_defense_panel_requests
CREATE TABLE `crad_defense_panel_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_ref` varchar(40) NOT NULL,
  `research_group_id` int(10) unsigned NOT NULL,
  `group_number` varchar(60) NOT NULL DEFAULT '',
  `student_id` varchar(40) NOT NULL DEFAULT '',
  `research_title` varchar(255) NOT NULL DEFAULT '',
  `adviser_name` varchar(160) NOT NULL DEFAULT '',
  `members_text` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Draft',
  `review_remarks` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_app_ref` (`application_ref`),
  KEY `idx_panel_app_group` (`research_group_id`),
  KEY `idx_panel_app_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_final_defense_evaluations
CREATE TABLE `crad_final_defense_evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `defense_schedule_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `panel_user_id` int(10) unsigned NOT NULL,
  `panel_name` varchar(150) NOT NULL DEFAULT '',
  `content_score` decimal(5,2) NOT NULL,
  `methodology_score` decimal(5,2) NOT NULL,
  `references_score` decimal(5,2) NOT NULL,
  `format_score` decimal(5,2) NOT NULL,
  `defense_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `result` enum('APPROVED','APPROVED WITH REVISION','FAILED') NOT NULL,
  `overall_score` decimal(5,2) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Submitted',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_final_panel_submission` (`defense_schedule_id`,`panel_user_id`),
  KEY `idx_final_group` (`research_group_id`),
  KEY `idx_final_panel` (`panel_user_id`),
  KEY `idx_final_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_final_defense_recommendations
CREATE TABLE `crad_final_defense_recommendations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `group_number` varchar(40) NOT NULL DEFAULT '',
  `adviser_user_id` int(10) unsigned DEFAULT NULL,
  `adviser_name` varchar(150) NOT NULL DEFAULT '',
  `status` enum('Not Ready','Recommended') NOT NULL DEFAULT 'Not Ready',
  `remarks` text DEFAULT NULL,
  `recommended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fdr_group` (`research_group_id`),
  KEY `idx_fdr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_final_manuscript_approvals
CREATE TABLE `crad_final_manuscript_approvals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `defense_schedule_id` int(10) unsigned DEFAULT NULL,
  `approved_by_user` int(10) unsigned DEFAULT NULL,
  `approved_by_name` varchar(150) NOT NULL DEFAULT '',
  `status` enum('Pending','Approved','Returned') NOT NULL DEFAULT 'Pending',
  `remarks` text DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fma_group` (`research_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_applications
CREATE TABLE `crad_grant_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_reference` varchar(30) DEFAULT NULL COMMENT 'Stable proposal ID e.g. GR-2026-001',
  `current_version` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Active proposal document version',
  `grant_opportunity_id` int(10) unsigned NOT NULL COMMENT 'FK → grant_opportunities.id',
  `research_group_id` int(10) unsigned DEFAULT NULL COMMENT 'FK → research_groups.id (nullable for non-capstone applicants)',
  `group_number` varchar(30) DEFAULT NULL,
  `research_title` varchar(500) DEFAULT NULL,
  `applicant_name` varchar(200) NOT NULL DEFAULT '',
  `college_dept` varchar(200) DEFAULT NULL COMMENT 'Academic college / department of the lead proponent',
  `requested_budget` decimal(14,2) DEFAULT NULL COMMENT 'Budget requested by the proponent; must not exceed grant max_funding_cap',
  `approved_budget` decimal(14,2) DEFAULT NULL,
  `abstract` text DEFAULT NULL COMMENT 'Executive abstract of the research proposal',
  `objectives` text DEFAULT NULL COMMENT 'Research objectives',
  `proposal_pdf` varchar(255) DEFAULT NULL COMMENT 'Stored filename of the uploaded proposal PDF/DOC under storage/uploads/grant_proposals/',
  `proposal_pdf_original` varchar(300) DEFAULT NULL COMMENT 'Original filename of the uploaded proposal document',
  `supporting_docs` varchar(255) DEFAULT NULL COMMENT 'Stored filename of optional supporting documents',
  `supporting_docs_original` varchar(300) DEFAULT NULL COMMENT 'Original filename of optional supporting documents',
  `ethics_doc` varchar(255) DEFAULT NULL COMMENT 'Stored filename of optional ethics clearance document',
  `ethics_doc_original` varchar(300) DEFAULT NULL COMMENT 'Original filename of optional ethics clearance document',
  `applicant_user_id` int(10) unsigned DEFAULT NULL,
  `application_notes` text DEFAULT NULL,
  `status` enum('Submitted','Under Review','Approved','Approved & Funded','Final Output Submitted','OUTPUT_VERIFIED','Archived','Denied','Withdrawn','Rejected','Revision Required','Resubmitted') NOT NULL DEFAULT 'Submitted',
  `submission_token` varchar(64) DEFAULT NULL COMMENT 'One-time token for duplicate-submission prevention',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ga_token` (`submission_token`),
  KEY `idx_ga_opportunity` (`grant_opportunity_id`),
  KEY `idx_ga_group` (`research_group_id`),
  KEY `idx_ga_status` (`status`),
  KEY `idx_ga_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_document_repository
CREATE TABLE `crad_grant_document_repository` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `archive_reference` varchar(40) NOT NULL DEFAULT '',
  `status` enum('ARCHIVED') NOT NULL DEFAULT 'ARCHIVED',
  `item_count` int(10) unsigned NOT NULL DEFAULT 0,
  `archived_by_user_id` int(10) unsigned DEFAULT NULL,
  `archived_by_name` varchar(120) DEFAULT NULL,
  `archived_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gdr_application` (`grant_application_id`),
  KEY `idx_gdr_reference` (`archive_reference`),
  KEY `idx_gdr_archived` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_document_repository_items
CREATE TABLE `crad_grant_document_repository_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `repository_id` int(10) unsigned NOT NULL,
  `grant_application_id` int(10) unsigned NOT NULL,
  `category` varchar(40) NOT NULL,
  `item_label` varchar(255) NOT NULL DEFAULT '',
  `item_type` enum('file','record') NOT NULL DEFAULT 'record',
  `file_path` varchar(255) DEFAULT NULL,
  `file_original` varchar(255) DEFAULT NULL,
  `download_url` varchar(500) DEFAULT NULL,
  `summary_text` text DEFAULT NULL,
  `metadata_json` text DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gdri_repository` (`repository_id`),
  KEY `idx_gdri_application` (`grant_application_id`),
  KEY `idx_gdri_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_final_output_submissions
CREATE TABLE `crad_grant_final_output_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL DEFAULT 1,
  `final_research_title` varchar(500) NOT NULL DEFAULT '',
  `authors` varchar(500) NOT NULL DEFAULT '',
  `abstract` text DEFAULT NULL,
  `publication_type` enum('Journal','Conference','Book Chapter','Repository','Other') NOT NULL DEFAULT 'Journal',
  `journal_conference` varchar(255) NOT NULL DEFAULT '',
  `doi` varchar(120) NOT NULL DEFAULT '',
  `publication_url` varchar(500) NOT NULL DEFAULT '',
  `ip_information` text DEFAULT NULL,
  `copyright_info` text DEFAULT NULL,
  `patent_info` text DEFAULT NULL,
  `other_ip_info` text DEFAULT NULL,
  `final_pdf_path` varchar(255) DEFAULT NULL,
  `final_pdf_original` varchar(255) DEFAULT NULL,
  `supporting_files_json` text DEFAULT NULL,
  `status` enum('FINAL_OUTPUT_SUBMITTED','RETURNED_FOR_CORRECTION','OUTPUT_VERIFIED') NOT NULL DEFAULT 'FINAL_OUTPUT_SUBMITTED',
  `return_reason` text DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `submitted_by_user_id` int(10) unsigned DEFAULT NULL,
  `submitted_by_name` varchar(120) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by_user_id` int(10) unsigned DEFAULT NULL,
  `reviewed_by_name` varchar(120) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gfos_application` (`grant_application_id`),
  KEY `idx_gfos_status` (`status`),
  KEY `idx_gfos_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_funded_progress_evidence
CREATE TABLE `crad_grant_funded_progress_evidence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `milestone_id` int(10) unsigned DEFAULT NULL,
  `evidence_title` varchar(200) NOT NULL DEFAULT '',
  `notes` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_original` varchar(255) DEFAULT NULL,
  `submitted_by_user_id` int(10) unsigned DEFAULT NULL,
  `submitted_by_name` varchar(120) DEFAULT NULL,
  `status` enum('Submitted','Acknowledged') NOT NULL DEFAULT 'Submitted',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gfpe_application` (`grant_application_id`),
  KEY `idx_gfpe_milestone` (`milestone_id`),
  KEY `idx_gfpe_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_funded_project_milestones
CREATE TABLE `crad_grant_funded_project_milestones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `milestone_order` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `milestone_name` varchar(120) NOT NULL,
  `due_date` date DEFAULT NULL,
  `completion_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `supporting_doc` varchar(255) DEFAULT NULL,
  `supporting_doc_original` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by_user_id` int(10) unsigned DEFAULT NULL,
  `updated_by_name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gfpm_app_order` (`grant_application_id`,`milestone_order`),
  KEY `idx_gfpm_application` (`grant_application_id`),
  KEY `idx_gfpm_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_funding_disbursements
CREATE TABLE `crad_grant_funding_disbursements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `tranche_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `tranche_label` varchar(80) NOT NULL DEFAULT '',
  `approved_budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_released` decimal(14,2) NOT NULL DEFAULT 0.00,
  `release_date` date DEFAULT NULL,
  `reference_number` varchar(80) DEFAULT NULL,
  `status` enum('Pending','Released','Cancelled') NOT NULL DEFAULT 'Pending',
  `released_by_user_id` int(10) unsigned DEFAULT NULL,
  `released_by_name` varchar(120) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gfd_app_tranche` (`grant_application_id`,`tranche_number`),
  KEY `idx_gfd_application` (`grant_application_id`),
  KEY `idx_gfd_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_opportunities
CREATE TABLE `crad_grant_opportunities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `funding_title` varchar(300) NOT NULL,
  `max_funding_cap` decimal(14,2) NOT NULL DEFAULT 0.00,
  `application_deadline` date NOT NULL,
  `eligibility` varchar(100) NOT NULL DEFAULT 'Open',
  `college_program` varchar(200) DEFAULT NULL COMMENT 'Populated when eligibility = Specific College/Program',
  `status` enum('Open for Application','Closed','Expired') NOT NULL DEFAULT 'Open for Application',
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_by_name` varchar(150) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_go_status` (`status`),
  KEY `idx_go_deadline` (`application_deadline`),
  KEY `idx_go_created_by` (`created_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_proposal_approval_steps
CREATE TABLE `crad_grant_proposal_approval_steps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int(10) unsigned NOT NULL,
  `grant_application_id` int(10) unsigned NOT NULL,
  `step_key` varchar(40) NOT NULL,
  `step_order` tinyint(3) unsigned NOT NULL,
  `step_label` varchar(80) NOT NULL,
  `approver_role_key` varchar(40) NOT NULL,
  `status` enum('Queued','Pending','Approved','Returned') NOT NULL DEFAULT 'Queued',
  `approver_user_id` int(10) unsigned DEFAULT NULL,
  `approver_name` varchar(150) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `signature_data` mediumtext DEFAULT NULL,
  `acted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpas_workflow_step` (`workflow_id`,`step_key`),
  KEY `idx_gpas_application` (`grant_application_id`),
  KEY `idx_gpas_status` (`status`),
  KEY `idx_gpas_role` (`approver_role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_proposal_approval_workflows
CREATE TABLE `crad_grant_proposal_approval_workflows` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `current_step_key` varchar(40) NOT NULL DEFAULT 'adviser',
  `workflow_status` enum('In Progress','Completed','Returned') NOT NULL DEFAULT 'In Progress',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpaw_application` (`grant_application_id`),
  KEY `idx_gpaw_status` (`workflow_status`),
  KEY `idx_gpaw_current_step` (`current_step_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_proposal_evaluations
CREATE TABLE `crad_grant_proposal_evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `proposal_version` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Proposal version evaluated',
  `evaluator_user_id` int(10) unsigned NOT NULL,
  `evaluator_name` varchar(150) NOT NULL DEFAULT '',
  `evaluation_type` varchar(20) NOT NULL DEFAULT 'committee' COMMENT 'committee | adviser',
  `score_rationale` decimal(5,2) NOT NULL DEFAULT 0.00,
  `score_methodology` decimal(5,2) NOT NULL DEFAULT 0.00,
  `score_budget` decimal(5,2) NOT NULL DEFAULT 0.00,
  `score_team_capability` decimal(5,2) NOT NULL DEFAULT 0.00,
  `score_compliance` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `comments` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `required_corrections` text DEFAULT NULL,
  `recommendation` varchar(40) DEFAULT NULL COMMENT 'Reviewer decision: disapprove | require_revisions',
  `revision_reason` text DEFAULT NULL COMMENT 'Reason for required revisions',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpe_app_eval_ver` (`grant_application_id`,`evaluator_user_id`,`proposal_version`),
  KEY `idx_gpe_application` (`grant_application_id`),
  KEY `idx_gpe_evaluator` (`evaluator_user_id`),
  KEY `idx_gpe_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_proposal_notifications
CREATE TABLE `crad_grant_proposal_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(120) NOT NULL,
  `recipient_user_id` int(10) unsigned DEFAULT NULL,
  `recipient_role` varchar(40) NOT NULL DEFAULT '',
  `recipient_email` varchar(190) NOT NULL DEFAULT '',
  `grant_application_id` int(10) unsigned NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL DEFAULT '',
  `body` text NOT NULL,
  `url` varchar(500) NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpn_event` (`event_key`),
  KEY `idx_gpn_recipient_user` (`recipient_user_id`),
  KEY `idx_gpn_application` (`grant_application_id`),
  KEY `idx_gpn_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_proposal_versions
CREATE TABLE `crad_grant_proposal_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `version_label` varchar(60) NOT NULL DEFAULT '',
  `proposal_pdf` varchar(255) DEFAULT NULL,
  `proposal_pdf_original` varchar(300) DEFAULT NULL,
  `supporting_docs` varchar(255) DEFAULT NULL,
  `supporting_docs_original` varchar(300) DEFAULT NULL,
  `ethics_doc` varchar(255) DEFAULT NULL,
  `ethics_doc_original` varchar(300) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `objectives` text DEFAULT NULL,
  `researcher_notes` text DEFAULT NULL,
  `submitted_by_user_id` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpv_app_ver` (`grant_application_id`,`version_number`),
  KEY `idx_gpv_application` (`grant_application_id`),
  KEY `idx_gpv_submitted` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_grant_publications_ip_repository
CREATE TABLE `crad_grant_publications_ip_repository` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `grant_application_id` int(10) unsigned NOT NULL,
  `submission_id` int(10) unsigned NOT NULL,
  `repository_reference` varchar(40) NOT NULL DEFAULT '',
  `final_research_title` varchar(500) NOT NULL DEFAULT '',
  `authors` varchar(500) NOT NULL DEFAULT '',
  `abstract` text DEFAULT NULL,
  `publication_type` varchar(60) NOT NULL DEFAULT '',
  `journal_conference` varchar(255) NOT NULL DEFAULT '',
  `doi` varchar(120) NOT NULL DEFAULT '',
  `publication_url` varchar(500) NOT NULL DEFAULT '',
  `ip_information` text DEFAULT NULL,
  `copyright_info` text DEFAULT NULL,
  `patent_info` text DEFAULT NULL,
  `other_ip_info` text DEFAULT NULL,
  `final_pdf_path` varchar(255) DEFAULT NULL,
  `final_pdf_original` varchar(255) DEFAULT NULL,
  `supporting_files_json` text DEFAULT NULL,
  `verified_by_user_id` int(10) unsigned DEFAULT NULL,
  `verified_by_name` varchar(120) DEFAULT NULL,
  `verified_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gpip_application` (`grant_application_id`),
  KEY `idx_gpip_reference` (`repository_reference`),
  KEY `idx_gpip_verified` (`verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_manuscript_evaluations
CREATE TABLE `crad_manuscript_evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned NOT NULL,
  `evaluator_user_id` int(10) unsigned NOT NULL,
  `evaluator_name` varchar(150) NOT NULL DEFAULT '',
  `content_score` decimal(5,2) NOT NULL,
  `methodology_score` decimal(5,2) NOT NULL,
  `results_score` decimal(5,2) NOT NULL,
  `conclusions_score` decimal(5,2) NOT NULL,
  `recommendations_score` decimal(5,2) NOT NULL,
  `references_score` decimal(5,2) NOT NULL,
  `formatting_score` decimal(5,2) NOT NULL,
  `compliance_score` decimal(5,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `result` enum('APPROVED','FOR REVISION') NOT NULL,
  `overall_score` decimal(5,2) NOT NULL,
  `evaluated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_meval_submission` (`submission_id`),
  KEY `idx_meval_group` (`research_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_manuscript_submissions
CREATE TABLE `crad_manuscript_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `status` enum('Submitted','Under Review','For Revision','Approved') NOT NULL DEFAULT 'Submitted',
  `submitted_by_user` int(10) unsigned DEFAULT NULL,
  `submitted_by_name` varchar(150) NOT NULL DEFAULT '',
  `submitted_by_email` varchar(190) NOT NULL DEFAULT '',
  `submission_notes` text DEFAULT NULL,
  `original_name` varchar(255) NOT NULL DEFAULT '',
  `stored_subdir` varchar(180) NOT NULL DEFAULT '',
  `stored_name` varchar(120) NOT NULL DEFAULT '',
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `file_mime` varchar(120) NOT NULL DEFAULT '',
  `submission_token` varchar(64) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manuscript_version` (`research_group_id`,`version_number`),
  UNIQUE KEY `uniq_manuscript_token` (`submission_token`),
  KEY `idx_manuscript_status` (`status`),
  KEY `idx_manuscript_group` (`research_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panel_application_documents
CREATE TABLE `crad_panel_application_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `document_type` varchar(40) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `file_data` longblob NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_application_document` (`application_id`,`document_type`),
  KEY `idx_panel_document_application` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panel_application_history
CREATE TABLE `crad_panel_application_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `old_status` varchar(32) DEFAULT NULL,
  `new_status` varchar(32) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `actor_name` varchar(180) NOT NULL DEFAULT 'Applicant',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_panel_application_history` (`application_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panel_assignment_notifications
CREATE TABLE `crad_panel_assignment_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(140) NOT NULL,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `recipient_role` varchar(60) NOT NULL DEFAULT 'panel',
  `recipient_email` varchar(190) NOT NULL DEFAULT '',
  `panel_assignment_id` int(10) unsigned DEFAULT NULL,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(160) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `url` varchar(500) NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_assignment_notification` (`event_key`),
  KEY `idx_panel_notification_recipient` (`recipient_user_id`,`recipient_role`,`recipient_email`),
  KEY `idx_panel_notification_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panel_member_availability
CREATE TABLE `crad_panel_member_availability` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `panel_user_id` int(10) unsigned NOT NULL,
  `availability_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `availability_windows_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_availability_user` (`panel_user_id`),
  KEY `idx_panel_availability_status` (`availability_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panelist_applications
CREATE TABLE `crad_panelist_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_ref` varchar(32) NOT NULL,
  `resume_token_hash` char(64) NOT NULL,
  `applicant_name` varchar(180) NOT NULL DEFAULT '',
  `applicant_email` varchar(190) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'Draft',
  `application_data` longtext NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_application_ref` (`application_ref`),
  UNIQUE KEY `uniq_panel_resume_token` (`resume_token_hash`),
  KEY `idx_panel_application_status` (`status`),
  KEY `idx_panel_application_email` (`applicant_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_panelist_pool
CREATE TABLE `crad_panelist_pool` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `application_ref` varchar(32) NOT NULL,
  `applicant_name` varchar(180) NOT NULL DEFAULT '',
  `applicant_email` varchar(190) NOT NULL DEFAULT '',
  `institution` varchar(190) NOT NULL DEFAULT '',
  `department` varchar(190) NOT NULL DEFAULT '',
  `position` varchar(190) NOT NULL DEFAULT '',
  `primary_specialization` varchar(190) NOT NULL DEFAULT '',
  `secondary_specialization` varchar(190) NOT NULL DEFAULT '',
  `research_areas` text NOT NULL,
  `expertise_keywords` text NOT NULL,
  `years_research_experience` varchar(80) NOT NULL DEFAULT '',
  `defense_preferences` text NOT NULL,
  `available_days` varchar(190) NOT NULL DEFAULT '',
  `available_time_ranges` varchar(190) NOT NULL DEFAULT '',
  `preferred_schedule` text NOT NULL,
  `unavailable_periods` text NOT NULL,
  `panel_status` varchar(32) NOT NULL DEFAULT 'Pending',
  `approval_date` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_pool_application` (`application_id`),
  KEY `idx_panel_pool_status` (`panel_status`),
  KEY `idx_panel_pool_name` (`applicant_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_preoral_defense_evaluations
CREATE TABLE `crad_preoral_defense_evaluations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `defense_schedule_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `panel_user_id` int(10) unsigned NOT NULL,
  `panel_name` varchar(150) NOT NULL DEFAULT '',
  `content_score` decimal(5,2) NOT NULL,
  `methodology_score` decimal(5,2) NOT NULL,
  `references_score` decimal(5,2) NOT NULL,
  `format_score` decimal(5,2) NOT NULL,
  `defense_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `result` enum('APPROVED','APPROVED WITH REVISION','FAILED') NOT NULL,
  `overall_score` decimal(5,2) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Submitted',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_preoral_panel_submission` (`defense_schedule_id`,`panel_user_id`),
  KEY `idx_preoral_group` (`research_group_id`),
  KEY `idx_preoral_panel` (`panel_user_id`),
  KEY `idx_preoral_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_proposal_documents
CREATE TABLE `crad_proposal_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `doc_key` varchar(60) NOT NULL COMMENT 'Slot key: manuscript, approval, abstract, etc.',
  `doc_title` varchar(200) NOT NULL,
  `original_name` varchar(300) NOT NULL,
  `stored_name` varchar(300) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Bytes',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pd_proposal` (`proposal_id`),
  CONSTRAINT `fk_pd_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `crad_research_proposals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_proposal_drafts
CREATE TABLE `crad_proposal_drafts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users (optional)',
  `form_type` varchar(30) NOT NULL DEFAULT 'document',
  `revision_ref` varchar(30) NOT NULL DEFAULT '' COMMENT 'Returned proposal ref when draft is for revision',
  `draft_data` longtext NOT NULL COMMENT 'JSON encoded draft form fields except upload files',
  `signature_data` mediumtext DEFAULT NULL COMMENT 'Base64 PNG of representative signature draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proposal_draft_student_type` (`student_id`,`form_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_proposal_members
CREATE TABLE `crad_proposal_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT '1 = lead member',
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `contact` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_proposal` (`proposal_id`),
  CONSTRAINT `fk_pm_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `crad_research_proposals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_proposal_status_logs
CREATE TABLE `crad_proposal_status_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users',
  `remarks` text DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_psl_proposal` (`proposal_id`),
  CONSTRAINT `fk_psl_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `crad_research_proposals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_publications
CREATE TABLE `crad_publications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `title` varchar(500) NOT NULL DEFAULT '',
  `authors` text DEFAULT NULL,
  `publication_outlet` varchar(255) NOT NULL DEFAULT '',
  `publication_date` date DEFAULT NULL,
  `doi_link` varchar(500) NOT NULL DEFAULT '',
  `status` enum('Draft','For Publication','Published','Archived') NOT NULL DEFAULT 'Draft',
  `notes` text DEFAULT NULL,
  `created_by_user` int(10) unsigned DEFAULT NULL,
  `created_by_name` varchar(150) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pub_group` (`research_group_id`),
  KEY `idx_pub_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_adviser_assignments
CREATE TABLE `crad_research_adviser_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `proposal_id` int(10) unsigned DEFAULT NULL,
  `proposal_number` varchar(30) DEFAULT NULL,
  `group_number` varchar(40) DEFAULT NULL,
  `student_id` varchar(40) DEFAULT NULL,
  `adviser_name` varchar(150) NOT NULL DEFAULT '',
  `adviser_email` varchar(190) NOT NULL DEFAULT '',
  `adviser_user_id` int(10) unsigned DEFAULT NULL,
  `expertise` varchar(255) NOT NULL DEFAULT '',
  `availability_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `assignment_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `confirmation_status` varchar(40) NOT NULL DEFAULT 'pending_confirmation',
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notification_sent_at` datetime DEFAULT NULL,
  `notification_sent_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_raa_group` (`research_group_id`),
  KEY `idx_raa_proposal` (`proposal_id`),
  KEY `idx_raa_group_number` (`group_number`),
  KEY `idx_raa_status` (`assignment_status`),
  KEY `idx_raa_user` (`adviser_user_id`),
  KEY `idx_raa_student` (`student_id`),
  CONSTRAINT `fk_raa_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `crad_research_proposals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_assignment_cycles
CREATE TABLE `crad_research_assignment_cycles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `group_number` varchar(40) NOT NULL DEFAULT '',
  `student_id` varchar(40) NOT NULL DEFAULT '',
  `coordinator_assignment_id` int(10) unsigned DEFAULT NULL,
  `adviser_assignment_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending_confirmation',
  `coordinator_confirmed_at` datetime DEFAULT NULL,
  `coordinator_confirmed_by` int(10) unsigned DEFAULT NULL,
  `adviser_confirmed_at` datetime DEFAULT NULL,
  `adviser_confirmed_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancel_role` varchar(40) NOT NULL DEFAULT '',
  `cancel_reason` text DEFAULT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rac_group` (`research_group_id`),
  KEY `idx_rac_group_number` (`group_number`),
  KEY `idx_rac_student` (`student_id`),
  KEY `idx_rac_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_clearance_notifications
CREATE TABLE `crad_research_clearance_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_key` varchar(190) NOT NULL,
  `recipient_user_id` int(10) unsigned DEFAULT NULL,
  `recipient_role` varchar(40) NOT NULL DEFAULT '',
  `recipient_email` varchar(190) NOT NULL DEFAULT '',
  `clearance_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL DEFAULT '',
  `title` varchar(190) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `url` varchar(255) NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rsc_notif_event` (`event_key`),
  KEY `idx_rsc_notif_recipient` (`recipient_user_id`,`recipient_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_clearance_payments
CREATE TABLE `crad_research_clearance_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `research_stage` varchar(20) NOT NULL DEFAULT 'research_1',
  `student_user_id` int(10) unsigned DEFAULT NULL,
  `uploaded_file` varchar(255) NOT NULL DEFAULT '',
  `uploaded_blob` mediumblob DEFAULT NULL,
  `uploaded_mime` varchar(100) NOT NULL DEFAULT '',
  `uploaded_size` int(10) unsigned NOT NULL DEFAULT 0,
  `uploaded_original` varchar(255) NOT NULL DEFAULT '',
  `or_number` varchar(80) NOT NULL DEFAULT '',
  `remarks` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `approved_by_user_id` int(10) unsigned DEFAULT NULL,
  `approved_by_name` varchar(160) NOT NULL DEFAULT '',
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rcp_group_stage` (`research_group_id`,`research_stage`),
  KEY `idx_rcp_group` (`research_group_id`),
  KEY `idx_rcp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_coordinator_assignments
CREATE TABLE `crad_research_coordinator_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `proposal_id` int(10) unsigned DEFAULT NULL,
  `title_approval_id` int(10) unsigned DEFAULT NULL,
  `proposal_number` varchar(30) DEFAULT NULL,
  `group_number` varchar(40) DEFAULT NULL,
  `group_name` varchar(120) NOT NULL DEFAULT '',
  `research_title` varchar(255) NOT NULL DEFAULT '',
  `student_id` varchar(40) DEFAULT NULL,
  `coordinator_user_id` int(10) unsigned DEFAULT NULL,
  `coordinator_name` varchar(200) NOT NULL DEFAULT '',
  `coordinator_email` varchar(200) NOT NULL DEFAULT '',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `confirmation_status` varchar(40) NOT NULL DEFAULT 'pending_confirmation',
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` int(10) unsigned DEFAULT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rca_group_number` (`group_number`),
  UNIQUE KEY `uniq_rca_group_coordinator` (`research_group_id`,`coordinator_user_id`),
  UNIQUE KEY `uniq_rca_student_id` (`student_id`),
  KEY `idx_rca_group` (`research_group_id`),
  KEY `idx_rca_title_approval` (`title_approval_id`),
  KEY `idx_rca_status` (`status`),
  KEY `idx_rca_student` (`student_id`),
  CONSTRAINT `fk_rca_title_approval` FOREIGN KEY (`title_approval_id`) REFERENCES `crad_title_approvals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_defense_schedule_history
CREATE TABLE `crad_research_defense_schedule_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `group_number` varchar(60) DEFAULT NULL,
  `defense_type` varchar(60) DEFAULT NULL,
  `old_datetime` datetime DEFAULT NULL,
  `old_end_datetime` datetime DEFAULT NULL,
  `old_venue` varchar(160) DEFAULT NULL,
  `new_datetime` datetime NOT NULL,
  `new_end_datetime` datetime NOT NULL,
  `new_venue` varchar(160) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `changed_by_user_id` int(10) unsigned DEFAULT NULL,
  `changed_by_name` varchar(160) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_defense_history_schedule` (`schedule_id`),
  KEY `idx_defense_history_group` (`research_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_defense_schedules
CREATE TABLE `crad_research_defense_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned DEFAULT NULL,
  `proposal_id` int(10) unsigned DEFAULT NULL,
  `proposal_number` varchar(30) DEFAULT NULL,
  `group_number` varchar(40) NOT NULL,
  `research_group` varchar(120) NOT NULL,
  `research_title` varchar(255) NOT NULL,
  `adviser_name` varchar(160) DEFAULT NULL,
  `panel_members` text DEFAULT NULL,
  `panel_chair` varchar(160) DEFAULT NULL,
  `venue` varchar(120) DEFAULT NULL,
  `venue_id` int(10) unsigned DEFAULT NULL,
  `defense_datetime` datetime DEFAULT NULL,
  `defense_end_datetime` datetime DEFAULT NULL,
  `defense_type` varchar(40) NOT NULL DEFAULT 'Pre-Oral',
  `status` varchar(40) NOT NULL DEFAULT 'Ready for Scheduling',
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `finalized_by` int(10) unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rds_proposal_number` (`proposal_number`),
  KEY `idx_rds_status` (`status`),
  KEY `idx_rds_proposal_id` (`proposal_id`),
  KEY `idx_rds_venue_time` (`venue_id`,`defense_datetime`,`defense_end_datetime`),
  KEY `idx_rds_group_time` (`research_group_id`,`defense_datetime`,`defense_end_datetime`),
  KEY `idx_rds_group_number` (`group_number`),
  CONSTRAINT `fk_rds_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `crad_research_proposals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_group_members
CREATE TABLE `crad_research_group_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `research_stage` varchar(20) NOT NULL DEFAULT 'research_1',
  `member_order` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `student_id` varchar(40) NOT NULL DEFAULT '',
  `full_name` varchar(160) NOT NULL DEFAULT '',
  `section` varchar(80) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `or_number` varchar(80) NOT NULL DEFAULT '',
  `is_leader` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rgm_group` (`research_group_id`),
  KEY `idx_rgm_group_stage` (`research_group_id`,`research_stage`),
  KEY `idx_rgm_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_groups
CREATE TABLE `crad_research_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned DEFAULT NULL,
  `title_approval_id` int(10) unsigned DEFAULT NULL,
  `proposal_number` varchar(30) DEFAULT NULL,
  `group_number` varchar(40) NOT NULL,
  `group_name` varchar(40) NOT NULL DEFAULT '',
  `research_title` varchar(255) NOT NULL DEFAULT '',
  `college_dept` varchar(120) NOT NULL DEFAULT '',
  `adviser` varchar(120) NOT NULL DEFAULT '',
  `academic_year` varchar(20) NOT NULL DEFAULT '',
  `leader_name` varchar(120) NOT NULL DEFAULT '',
  `leader_id` varchar(40) NOT NULL DEFAULT '',
  `leader_email` varchar(120) NOT NULL DEFAULT '',
  `leader_contact` varchar(40) NOT NULL DEFAULT '',
  `status` varchar(40) NOT NULL DEFAULT 'Approved',
  `flow_status` varchar(40) NOT NULL DEFAULT 'draft',
  `incomplete_reason` text DEFAULT NULL,
  `is_complete` tinyint(1) NOT NULL DEFAULT 0,
  `member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `min_members_required` int(10) unsigned NOT NULL DEFAULT 3,
  `submitted_by_user_id` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `dh_decision` varchar(20) NOT NULL DEFAULT '',
  `dh_decision_by` int(10) unsigned DEFAULT NULL,
  `dh_decision_at` datetime DEFAULT NULL,
  `dh_remarks` text DEFAULT NULL,
  `date_assigned` date NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_number` (`group_number`),
  UNIQUE KEY `proposal_id` (`proposal_id`),
  UNIQUE KEY `title_approval_id` (`title_approval_id`),
  KEY `idx_rg_proposal_number` (`proposal_number`),
  CONSTRAINT `fk_rg_title_approval` FOREIGN KEY (`title_approval_id`) REFERENCES `crad_title_approvals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_milestones
CREATE TABLE `crad_research_milestones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_plan_id` int(10) unsigned NOT NULL,
  `milestone_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `milestone_order` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `progress_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `weight` decimal(5,2) NOT NULL DEFAULT 1.00 COMMENT 'For weighted progress calculation',
  `status` enum('Not Started','In Progress','Submitted for Review','Revision Requested','Approved','Completed') NOT NULL DEFAULT 'Not Started',
  `start_date` date DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `researcher_notes` text DEFAULT NULL,
  `adviser_remarks` text DEFAULT NULL,
  `panel_remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rm_plan_name` (`research_plan_id`,`milestone_name`),
  KEY `idx_rm_plan` (`research_plan_id`),
  KEY `idx_rm_status` (`status`),
  KEY `idx_rm_sequence` (`research_plan_id`,`milestone_order`),
  CONSTRAINT `fk_rm_research_plan` FOREIGN KEY (`research_plan_id`) REFERENCES `crad_research_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_panel_assignments
CREATE TABLE `crad_research_panel_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `defense_schedule_id` int(10) unsigned DEFAULT NULL,
  `proposal_id` int(10) unsigned DEFAULT NULL,
  `title_approval_id` int(10) unsigned DEFAULT NULL,
  `proposal_number` varchar(30) DEFAULT NULL,
  `group_number` varchar(40) NOT NULL DEFAULT '',
  `research_title` varchar(255) NOT NULL DEFAULT '',
  `panel_user_id` int(10) unsigned NOT NULL,
  `panel_name` varchar(150) NOT NULL DEFAULT '',
  `panel_email` varchar(190) NOT NULL DEFAULT '',
  `expertise` varchar(255) NOT NULL DEFAULT '',
  `availability_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `assignment_status` varchar(40) NOT NULL DEFAULT 'Assigned',
  `defense_phase` varchar(60) NOT NULL DEFAULT 'Pre-Oral Defense',
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_panel_assignment_phase` (`research_group_id`,`panel_user_id`,`defense_phase`),
  KEY `idx_panel_assignment_group` (`research_group_id`),
  KEY `idx_panel_assignment_user` (`panel_user_id`),
  KEY `idx_panel_assignment_status` (`assignment_status`),
  KEY `idx_panel_assignment_schedule` (`defense_schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_plans
CREATE TABLE `crad_research_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to research_groups; nullable to preserve history if group is removed',
  `research_title` varchar(500) NOT NULL DEFAULT '',
  `group_number` varchar(40) NOT NULL DEFAULT '',
  `adviser_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users (adviser)',
  `adviser_name` varchar(150) NOT NULL DEFAULT '',
  `adviser_email` varchar(190) NOT NULL DEFAULT '',
  `start_date` date DEFAULT NULL,
  `target_completion_date` date DEFAULT NULL,
  `current_stage` varchar(100) NOT NULL DEFAULT 'Planning',
  `overall_progress` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Auto-calculated from milestones',
  `status` enum('Active','Completed','On Hold','Cancelled') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `final_defense_recommended` tinyint(1) NOT NULL DEFAULT 0,
  `final_defense_recommended_by` int(10) unsigned DEFAULT NULL,
  `final_defense_recommended_by_name` varchar(150) DEFAULT NULL,
  `final_defense_recommended_at` datetime DEFAULT NULL,
  `final_defense_recommendation_remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rp_group` (`research_group_id`),
  KEY `idx_rp_group_number` (`group_number`),
  KEY `idx_rp_adviser` (`adviser_id`),
  KEY `idx_rp_status` (`status`),
  CONSTRAINT `fk_rp_research_group` FOREIGN KEY (`research_group_id`) REFERENCES `crad_research_groups` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_activity_logs
CREATE TABLE `crad_research_progress_activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_plan_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users',
  `user_name` varchar(150) NOT NULL DEFAULT '',
  `user_role` varchar(40) NOT NULL DEFAULT '',
  `action` varchar(100) NOT NULL COMMENT 'milestone_created, progress_updated, feedback_added, etc',
  `entity_type` varchar(50) NOT NULL DEFAULT '' COMMENT 'milestone, progress_update, feedback, etc',
  `entity_id` int(10) unsigned DEFAULT NULL,
  `old_value` text DEFAULT NULL COMMENT 'JSON or text of previous state',
  `new_value` text DEFAULT NULL COMMENT 'JSON or text of new state',
  `description` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpal_plan` (`research_plan_id`),
  KEY `idx_rpal_user` (`user_id`),
  KEY `idx_rpal_action` (`action`),
  KEY `idx_rpal_entity` (`entity_type`,`entity_id`),
  KEY `idx_rpal_created` (`created_at`),
  CONSTRAINT `fk_rpal_research_plan` FOREIGN KEY (`research_plan_id`) REFERENCES `crad_research_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_ai_analyses
CREATE TABLE `crad_research_progress_ai_analyses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `progress_update_id` int(10) unsigned NOT NULL,
  `attachment_id` int(10) unsigned NOT NULL DEFAULT 0,
  `milestone_name` varchar(180) NOT NULL DEFAULT '',
  `verdict` varchar(40) NOT NULL DEFAULT 'advisory_only',
  `grammar_quality` varchar(40) NOT NULL DEFAULT 'not_scored',
  `summary` text NOT NULL,
  `notes_json` mediumtext NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'openai_gpt_4_1',
  `analyzed_by` int(10) unsigned NOT NULL DEFAULT 0,
  `analyzed_by_name` varchar(180) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpai_update` (`progress_update_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_attachments
CREATE TABLE `crad_research_progress_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `progress_update_id` int(10) unsigned NOT NULL,
  `file_name` varchar(300) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Bytes',
  `uploaded_by` int(10) unsigned NOT NULL COMMENT 'FK to sms2_users',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpa_update` (`progress_update_id`),
  KEY `idx_rpa_uploaded` (`uploaded_by`),
  CONSTRAINT `fk_rpa_progress_update` FOREIGN KEY (`progress_update_id`) REFERENCES `crad_research_progress_updates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_feedback
CREATE TABLE `crad_research_progress_feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `progress_update_id` int(10) unsigned DEFAULT NULL COMMENT 'Can be NULL for general milestone feedback',
  `milestone_id` int(10) unsigned DEFAULT NULL,
  `research_plan_id` int(10) unsigned NOT NULL,
  `adviser_user_id` int(10) unsigned NOT NULL,
  `adviser_name` varchar(200) NOT NULL DEFAULT '',
  `feedback_text` text NOT NULL,
  `new_milestone_status` varchar(60) DEFAULT NULL,
  `submission_token` varchar(64) DEFAULT NULL,
  `feedback_type` enum('Comment','Revision Request','Approval','Progress Approved') NOT NULL DEFAULT 'Comment',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpf_update` (`progress_update_id`),
  KEY `idx_rpf_milestone` (`milestone_id`),
  KEY `idx_rpf_plan` (`research_plan_id`),
  KEY `idx_rpf_created` (`created_at`),
  KEY `idx_rpf_adviser` (`adviser_user_id`),
  KEY `idx_rpf_token` (`submission_token`),
  KEY `idx_rpf_update_adviser` (`progress_update_id`,`adviser_user_id`),
  KEY `idx_rpf_plan_type` (`research_plan_id`,`feedback_type`),
  CONSTRAINT `fk_rpf_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `crad_research_milestones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rpf_progress_update` FOREIGN KEY (`progress_update_id`) REFERENCES `crad_research_progress_updates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rpf_research_plan` FOREIGN KEY (`research_plan_id`) REFERENCES `crad_research_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_notifications
CREATE TABLE `crad_research_progress_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users.id (NULL = role-based)',
  `recipient_email` varchar(200) NOT NULL DEFAULT '',
  `recipient_role` varchar(40) NOT NULL DEFAULT '',
  `batch_key` varchar(100) NOT NULL DEFAULT '' COMMENT 'Unique key per event for deduplication',
  `notification_type` varchar(60) NOT NULL DEFAULT 'progress_update',
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` text NOT NULL,
  `related_entity_type` varchar(60) NOT NULL DEFAULT '',
  `related_entity_id` int(10) unsigned DEFAULT NULL,
  `action_url` varchar(500) DEFAULT NULL,
  `status` enum('unread','read') NOT NULL DEFAULT 'unread',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rpn_recipient_user` (`recipient_user_id`),
  KEY `idx_rpn_recipient_email` (`recipient_email`),
  KEY `idx_rpn_recipient_role` (`recipient_role`),
  KEY `idx_rpn_batch_key` (`batch_key`),
  KEY `idx_rpn_status` (`status`),
  KEY `idx_rpn_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_progress_updates
CREATE TABLE `crad_research_progress_updates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_plan_id` int(10) unsigned NOT NULL,
  `research_group_id` int(10) unsigned NOT NULL,
  `milestone_id` int(10) unsigned DEFAULT NULL,
  `submitted_by_user_id` int(10) unsigned NOT NULL,
  `submitted_by_name` varchar(200) NOT NULL DEFAULT '',
  `update_title` varchar(300) NOT NULL,
  `accomplishments` text DEFAULT NULL,
  `problems_blockers` text DEFAULT NULL,
  `next_planned_activity` text DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_original_name` varchar(300) DEFAULT NULL,
  `submission_token` varchar(64) DEFAULT NULL,
  `previous_progress` decimal(5,2) DEFAULT NULL,
  `new_progress` decimal(5,2) NOT NULL,
  `milestone_status` varchar(60) NOT NULL DEFAULT 'In Progress',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rpu_plan` (`research_plan_id`),
  KEY `idx_rpu_milestone` (`milestone_id`),
  KEY `idx_rpu_researcher` (`submitted_by_user_id`),
  KEY `idx_rpu_submitted` (`submitted_at`),
  KEY `idx_rpu_group` (`research_group_id`),
  KEY `idx_rpu_token` (`submission_token`),
  KEY `idx_rpu_group_milestone` (`research_group_id`,`milestone_id`),
  KEY `idx_rpu_plan_submitted` (`research_plan_id`,`submitted_at`),
  CONSTRAINT `fk_rpu_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `crad_research_milestones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rpu_research_plan` FOREIGN KEY (`research_plan_id`) REFERENCES `crad_research_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_proposals
CREATE TABLE `crad_research_proposals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ref_code` varchar(30) NOT NULL COMMENT 'Auto-generated reference e.g. CRD-2026-00001',
  `proposal_number` varchar(30) DEFAULT NULL COMMENT 'Official number generated after approved proposal registration',
  `research_title` varchar(500) NOT NULL,
  `program_course` varchar(200) NOT NULL,
  `year_section` varchar(100) NOT NULL,
  `college_department` varchar(200) NOT NULL,
  `research_adviser` varchar(200) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `rep_name` varchar(200) NOT NULL,
  `rep_id` varchar(50) NOT NULL,
  `rep_email` varchar(200) NOT NULL,
  `rep_contact` varchar(20) NOT NULL,
  `status` enum('Submitted','In Progress','Panel Assigned','Approved','Returned') NOT NULL DEFAULT 'Submitted',
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 10 COMMENT 'Progress % shown in tracking',
  `date_submitted` date NOT NULL,
  `approved_at` datetime DEFAULT NULL COMMENT 'Date/time when tracking proposal was approved',
  `registered_at` datetime DEFAULT NULL COMMENT 'Date/time when approved proposal received official proposal number',
  `registration_status` enum('Pending','Registered') NOT NULL DEFAULT 'Pending',
  `signature_data` mediumtext DEFAULT NULL COMMENT 'Base64 PNG of representative signature',
  `submitted_by_user` int(10) unsigned DEFAULT NULL COMMENT 'FK to sms2_users (optional)',
  `notes` text DEFAULT NULL COMMENT 'CRAD officer notes',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ref_code` (`ref_code`),
  UNIQUE KEY `proposal_number` (`proposal_number`),
  KEY `idx_status` (`status`),
  KEY `idx_dept` (`college_department`(50)),
  KEY `idx_submitted` (`date_submitted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_revision_cycles
CREATE TABLE `crad_research_revision_cycles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `defense_schedule_id` int(10) unsigned NOT NULL,
  `official_result` varchar(60) NOT NULL DEFAULT 'APPROVED WITH REVISION',
  `revision_status` varchar(60) NOT NULL DEFAULT 'Needs Revision',
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `original_name` varchar(255) NOT NULL DEFAULT '',
  `stored_subdir` varchar(180) NOT NULL DEFAULT '',
  `stored_name` varchar(120) NOT NULL DEFAULT '',
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `file_mime` varchar(120) NOT NULL DEFAULT '',
  `submission_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rrc_schedule` (`defense_schedule_id`),
  KEY `idx_rrc_group` (`research_group_id`),
  KEY `idx_rrc_status` (`revision_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_services_clearances
CREATE TABLE `crad_research_services_clearances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `research_group_id` int(10) unsigned NOT NULL,
  `research_stage` varchar(20) NOT NULL DEFAULT 'research_1',
  `title_approval_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'draft',
  `or_number` varchar(80) NOT NULL DEFAULT '',
  `leader_student_no` varchar(40) NOT NULL DEFAULT '',
  `leader_group_no` varchar(40) NOT NULL DEFAULT '',
  `program` varchar(200) NOT NULL DEFAULT '',
  `section` varchar(80) NOT NULL DEFAULT '',
  `research_title` varchar(255) NOT NULL DEFAULT '',
  `members_json` longtext DEFAULT NULL,
  `grammarian_name` varchar(160) NOT NULL DEFAULT '',
  `statistician_name` varchar(160) NOT NULL DEFAULT '',
  `adviser_name` varchar(160) NOT NULL DEFAULT '',
  `adviser_user_id` int(10) unsigned DEFAULT NULL,
  `adviser_email` varchar(190) NOT NULL DEFAULT '',
  `adviser_signature` longtext DEFAULT NULL,
  `adviser_signed_at` datetime DEFAULT NULL,
  `crad_name` varchar(160) NOT NULL DEFAULT '',
  `crad_user_id` int(10) unsigned DEFAULT NULL,
  `crad_signature` longtext DEFAULT NULL,
  `crad_signed_at` datetime DEFAULT NULL,
  `crad_remarks` varchar(500) NOT NULL DEFAULT '',
  `uploaded_file` varchar(255) DEFAULT NULL,
  `uploaded_blob` mediumblob DEFAULT NULL,
  `uploaded_mime` varchar(100) NOT NULL DEFAULT '',
  `uploaded_size` int(10) unsigned NOT NULL DEFAULT 0,
  `uploaded_original` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `mis_verified` tinyint(1) NOT NULL DEFAULT 0,
  `aa_verified` tinyint(1) NOT NULL DEFAULT 0,
  `mis_verified_at` datetime DEFAULT NULL,
  `aa_verified_at` datetime DEFAULT NULL,
  `export_hash` varchar(64) NOT NULL DEFAULT '',
  `form_verified` tinyint(1) NOT NULL DEFAULT 0,
  `mis_signature` longtext DEFAULT NULL,
  `aa_signature` longtext DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rsc_group_stage` (`research_group_id`,`research_stage`),
  KEY `idx_rsc_status` (`status`),
  KEY `idx_rsc_adviser` (`adviser_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_research_venues
CREATE TABLE `crad_research_venues` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `venue_name` varchar(160) NOT NULL,
  `capacity` int(10) unsigned NOT NULL DEFAULT 0,
  `venue_type` varchar(80) NOT NULL DEFAULT '',
  `status` varchar(40) NOT NULL DEFAULT 'Available',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_research_venue_name` (`venue_name`),
  KEY `idx_research_venues_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: crad_title_approvals
CREATE TABLE `crad_title_approvals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL DEFAULT '',
  `student_user_id` int(10) unsigned DEFAULT NULL,
  `student_name` varchar(200) NOT NULL DEFAULT '',
  `submission_date` date NOT NULL,
  `department` varchar(200) NOT NULL DEFAULT '',
  `proposed_title` varchar(500) NOT NULL DEFAULT '',
  `discipline_cluster` varchar(200) NOT NULL DEFAULT '',
  `primary_sdg` varchar(120) NOT NULL DEFAULT '',
  `research_agenda` varchar(300) NOT NULL DEFAULT '',
  `sdg_justification` text NOT NULL,
  `members_json` longtext NOT NULL,
  `adviser_name` varchar(200) NOT NULL DEFAULT '',
  `adviser_email` varchar(200) NOT NULL DEFAULT '',
  `coordinator_name` varchar(200) NOT NULL DEFAULT '',
  `proposal_number` varchar(30) DEFAULT NULL,
  `status` enum('Pending','Reviewed','Approved','Returned') NOT NULL DEFAULT 'Pending',
  `adviser_remarks` text DEFAULT NULL,
  `adviser_signature_data` mediumtext DEFAULT NULL,
  `coordinator_status` varchar(30) NOT NULL DEFAULT 'Not Ready',
  `coordinator_remarks` text DEFAULT NULL,
  `coordinator_screening_json` text DEFAULT NULL,
  `coordinator_signature_data` mediumtext DEFAULT NULL,
  `coordinator_reviewed_at` datetime DEFAULT NULL,
  `crad_status` varchar(30) NOT NULL DEFAULT 'Not Ready',
  `crad_signature_data` mediumtext DEFAULT NULL,
  `crad_reviewed_at` datetime DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ta_student_id` (`student_id`),
  KEY `idx_ta_adviser_email` (`adviser_email`(100)),
  KEY `idx_ta_status` (`status`),
  KEY `idx_ta_sent_at` (`sent_at`),
  KEY `idx_ta_proposal_number` (`proposal_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

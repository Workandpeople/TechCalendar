-- Aggregate-only audit. Run against an offline restored copy, never the live source.
SET SESSION time_zone='+00:00';
SET SESSION max_execution_time=120000;
START TRANSACTION READ ONLY;

SELECT JSON_OBJECT('section','tables','table',TABLE_NAME,'engine',ENGINE,
    'coverage',CASE WHEN TABLE_NAME IN ('failed_jobs','migrations','password_reset_tokens','password_resets','personal_access_tokens') THEN 'backup_only_do_not_reactivate'
        WHEN TABLE_NAME IN ('clients','dossier_comment','dossier_document','dossier_fiche_facturation','dossier_fiche_rdv','dossier_fiches','dossier_historique','dossier_letige','dossiers','entreprise','fiche_document','fiche_type','lot_dossier','mail_templates','media','planings','rdv_extern','sms_tempalte','status','user_type','users') THEN 'business_source'
        ELSE 'unmapped_review_required' END)
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;

SELECT JSON_OBJECT('section','counts','table',table_name,'rows',row_count)
FROM (
    SELECT 'clients' table_name,COUNT(*) row_count FROM clients
    UNION ALL SELECT 'dossier_comment',COUNT(*) FROM dossier_comment
    UNION ALL SELECT 'dossier_document',COUNT(*) FROM dossier_document
    UNION ALL SELECT 'dossier_fiche_facturation',COUNT(*) FROM dossier_fiche_facturation
    UNION ALL SELECT 'dossier_fiche_rdv',COUNT(*) FROM dossier_fiche_rdv
    UNION ALL SELECT 'dossier_fiches',COUNT(*) FROM dossier_fiches
    UNION ALL SELECT 'dossier_historique',COUNT(*) FROM dossier_historique
    UNION ALL SELECT 'dossier_letige',COUNT(*) FROM dossier_letige
    UNION ALL SELECT 'dossiers',COUNT(*) FROM dossiers
    UNION ALL SELECT 'entreprise',COUNT(*) FROM entreprise
    UNION ALL SELECT 'failed_jobs',COUNT(*) FROM failed_jobs
    UNION ALL SELECT 'fiche_document',COUNT(*) FROM fiche_document
    UNION ALL SELECT 'fiche_type',COUNT(*) FROM fiche_type
    UNION ALL SELECT 'lot_dossier',COUNT(*) FROM lot_dossier
    UNION ALL SELECT 'mail_templates',COUNT(*) FROM mail_templates
    UNION ALL SELECT 'media',COUNT(*) FROM media
    UNION ALL SELECT 'migrations',COUNT(*) FROM migrations
    UNION ALL SELECT 'password_reset_tokens',COUNT(*) FROM password_reset_tokens
    UNION ALL SELECT 'password_resets',COUNT(*) FROM password_resets
    UNION ALL SELECT 'personal_access_tokens',COUNT(*) FROM personal_access_tokens
    UNION ALL SELECT 'planings',COUNT(*) FROM planings
    UNION ALL SELECT 'rdv_extern',COUNT(*) FROM rdv_extern
    UNION ALL SELECT 'sms_tempalte',COUNT(*) FROM sms_tempalte
    UNION ALL SELECT 'status',COUNT(*) FROM status
    UNION ALL SELECT 'user_type',COUNT(*) FROM user_type
    UNION ALL SELECT 'users',COUNT(*) FROM users
) counts ORDER BY table_name;

SELECT JSON_OBJECT('section','user_types','source_type',t.id,'label',t.name,'type_status',t.status,
    'status_permissions',t.autorisation,'module_permissions',t.autorisation_types,
    'accounts',COUNT(u.id),'active_not_archived',COALESCE(SUM(u.actif=1 AND COALESCE(u.is_archived,0)=0),0))
FROM user_type t LEFT JOIN users u ON u.type=t.id GROUP BY t.id,t.name,t.status,t.autorisation,t.autorisation_types ORDER BY t.id;

SELECT JSON_OBJECT('section','password_formats','format',format_name,'accounts',COUNT(*))
FROM (SELECT CASE WHEN CHAR_LENGTH(password)=60 AND LEFT(password,4) IN ('$2y$','$2b$','$2a$') THEN 'bcrypt_candidate'
    WHEN LEFT(password,10)='$argon2id$' THEN 'argon2id_candidate'
    WHEN password='' THEN 'empty' ELSE 'other' END format_name FROM users) formats GROUP BY format_name;

SELECT JSON_OBJECT('section','user_quality','metric',metric,'count',quantity) FROM (
    SELECT 'blank_email' metric,COUNT(*) quantity FROM users WHERE TRIM(email)=''
    UNION ALL SELECT 'email_over_255',COUNT(*) FROM users WHERE CHAR_LENGTH(TRIM(email))>255
    UNION ALL SELECT 'duplicate_normalized_email_groups',COUNT(*) FROM (SELECT LOWER(TRIM(email)) normalized_email FROM users GROUP BY normalized_email HAVING COUNT(*)>1) duplicates
    UNION ALL SELECT 'missing_profile',COUNT(*) FROM users u LEFT JOIN user_type t ON t.id=u.type WHERE t.id IS NULL
    UNION ALL SELECT 'missing_parent_account',COUNT(*) FROM users u LEFT JOIN users p ON p.id=u.parent_id WHERE u.parent_id IS NOT NULL AND u.parent_id<>0 AND p.id IS NULL
    UNION ALL SELECT 'self_parent',COUNT(*) FROM users WHERE parent_id=id
) quality;

SELECT JSON_OBJECT('section','parent_profiles','child_type',u.type,'parent_type',p.type,'accounts',COUNT(*))
FROM users u LEFT JOIN users p ON p.id=u.parent_id WHERE u.parent_id IS NOT NULL AND u.parent_id<>0 GROUP BY u.type,p.type;

SELECT JSON_OBJECT('section','statuses','source_id',s.id,'label',s.name,'enabled',s.status,'operations',COUNT(f.id))
FROM status s LEFT JOIN dossier_fiches f ON f.status_id=s.id GROUP BY s.id,s.name,s.status ORDER BY s.id;

SELECT JSON_OBJECT('section','relations','metric',metric,'count',quantity) FROM (
    SELECT 'dossiers_without_operations' metric,COUNT(*) quantity FROM dossiers d WHERE NOT EXISTS(SELECT 1 FROM dossier_fiches f WHERE f.dossier_id=d.id)
    UNION ALL SELECT 'dossiers_with_multiple_operations',COUNT(*) FROM (SELECT dossier_id FROM dossier_fiches GROUP BY dossier_id HAVING COUNT(*)>1) multiples
    UNION ALL SELECT 'operation_missing_dossier',COUNT(*) FROM dossier_fiches f LEFT JOIN dossiers d ON d.id=f.dossier_id WHERE d.id IS NULL
    UNION ALL SELECT 'operation_missing_service',COUNT(*) FROM dossier_fiches f LEFT JOIN fiche_type t ON t.id=f.fiche_id WHERE t.id IS NULL
    UNION ALL SELECT 'operation_missing_status',COUNT(*) FROM dossier_fiches f LEFT JOIN status s ON s.id=f.status_id WHERE s.id IS NULL
    UNION ALL SELECT 'appointment_missing_dossier',COUNT(*) FROM dossier_fiche_rdv r LEFT JOIN dossiers d ON d.id=r.dossier_id WHERE d.id IS NULL
    UNION ALL SELECT 'appointment_missing_operation',COUNT(*) FROM dossier_fiche_rdv r LEFT JOIN dossier_fiches f ON f.id=r.dossier_fiche WHERE f.id IS NULL
    UNION ALL SELECT 'appointment_parent_mismatch',COUNT(*) FROM dossier_fiche_rdv r JOIN dossier_fiches f ON f.id=r.dossier_fiche WHERE f.dossier_id<>r.dossier_id
    UNION ALL SELECT 'document_missing_dossier',COUNT(*) FROM dossier_document x LEFT JOIN dossiers d ON d.id=x.dossier_id WHERE d.id IS NULL
    UNION ALL SELECT 'comment_missing_dossier',COUNT(*) FROM dossier_comment x LEFT JOIN dossiers d ON d.id=x.dossier_id WHERE d.id IS NULL
    UNION ALL SELECT 'history_missing_dossier',COUNT(*) FROM dossier_historique x LEFT JOIN dossiers d ON d.id=x.dossier_id WHERE d.id IS NULL
    UNION ALL SELECT 'history_missing_operation',COUNT(*) FROM dossier_historique x LEFT JOIN dossier_fiches f ON f.id=x.dossier_fiche_id WHERE x.dossier_fiche_id IS NOT NULL AND x.dossier_fiche_id<>0 AND f.id IS NULL
    UNION ALL SELECT 'dossier_missing_author',COUNT(*) FROM dossiers d LEFT JOIN users u ON u.id=d.author_id WHERE u.id IS NULL
    UNION ALL SELECT 'dossier_missing_installer',COUNT(*) FROM dossiers d LEFT JOIN entreprise e ON e.id=d.entreprise_id WHERE d.entreprise_id IS NOT NULL AND d.entreprise_id<>0 AND e.id IS NULL
    UNION ALL SELECT 'dossier_distinct_client_and_delegataire',COUNT(*) FROM dossiers WHERE client_id IS NOT NULL AND client_id<>0 AND delegataire_id IS NOT NULL AND delegataire_id<>0 AND client_id<>delegataire_id
) relations;

SELECT JSON_OBJECT('section','dossier_account_profiles','field',relation_name,'source_type',profile,'dossiers',quantity)
FROM (
    SELECT 'client_id' relation_name,u.type profile,COUNT(*) quantity FROM dossiers d LEFT JOIN users u ON u.id=d.client_id WHERE d.client_id IS NOT NULL AND d.client_id<>0 GROUP BY u.type
    UNION ALL SELECT 'delegataire_id',u.type,COUNT(*) FROM dossiers d LEFT JOIN users u ON u.id=d.delegataire_id WHERE d.delegataire_id IS NOT NULL AND d.delegataire_id<>0 GROUP BY u.type
    UNION ALL SELECT 'technicien_id',u.type,COUNT(*) FROM dossiers d LEFT JOIN users u ON u.id=d.technicien_id WHERE d.technicien_id IS NOT NULL AND d.technicien_id<>0 GROUP BY u.type
    UNION ALL SELECT 'admin_id',u.type,COUNT(*) FROM dossiers d LEFT JOIN users u ON u.id=d.admin_id WHERE d.admin_id IS NOT NULL AND d.admin_id<>0 GROUP BY u.type
    UNION ALL SELECT 'auditeur_id',u.type,COUNT(*) FROM dossiers d LEFT JOIN users u ON u.id=d.auditeur_id WHERE d.auditeur_id IS NOT NULL AND d.auditeur_id<>0 GROUP BY u.type
) profiles;

SELECT JSON_OBJECT('section','appointment_dates','source_table',source_table,'period',period,'rows',COUNT(*))
FROM (
    SELECT 'dossier_fiche_rdv' source_table,CASE WHEN date_rdv IS NULL OR date_rdv<'1000-01-01' THEN 'invalid_or_missing' WHEN date_rdv<@reference_date THEN 'past' WHEN date_rdv=@reference_date THEN 'same_day' ELSE 'future' END period FROM dossier_fiche_rdv
    UNION ALL SELECT 'rdv_extern',CASE WHEN date_rdv IS NULL OR date_rdv<'1000-01-01' THEN 'invalid_or_missing' WHEN date_rdv<@reference_date THEN 'past' WHEN date_rdv=@reference_date THEN 'same_day' ELSE 'future' END FROM rdv_extern
) dates GROUP BY source_table,period;

SELECT JSON_OBJECT('section','appointment_quality','metric',metric,'count',quantity) FROM (
    SELECT 'appointment_missing_date_or_time' metric,COUNT(*) quantity FROM dossier_fiche_rdv WHERE date_rdv IS NULL OR date_rdv<'1000-01-01' OR heure IS NULL OR heure<'00:00:00' OR heure>='24:00:00'
    UNION ALL SELECT 'appointment_duration_not_projectable',COUNT(*) FROM dossier_fiche_rdv WHERE rdv_duree IS NULL OR rdv_duree<1 OR rdv_duree>24
    UNION ALL SELECT 'appointment_missing_technician',COUNT(*) FROM dossier_fiche_rdv r JOIN dossiers d ON d.id=r.dossier_id LEFT JOIN users u ON u.id=d.technicien_id WHERE u.id IS NULL
    UNION ALL SELECT 'appointment_technician_not_field_profile',COUNT(*) FROM dossier_fiche_rdv r JOIN dossiers d ON d.id=r.dossier_id JOIN users u ON u.id=d.technicien_id WHERE u.type<>4
    UNION ALL SELECT 'appointments_with_incomplete_schedule_or_assignment',COUNT(*) FROM dossier_fiche_rdv r JOIN dossiers d ON d.id=r.dossier_id LEFT JOIN users u ON u.id=d.technicien_id WHERE r.date_rdv IS NULL OR r.date_rdv<'1000-01-01' OR r.heure IS NULL OR r.heure<'00:00:00' OR r.heure>='24:00:00' OR r.rdv_duree IS NULL OR r.rdv_duree<1 OR r.rdv_duree>24 OR u.id IS NULL OR u.type<>4
    UNION ALL SELECT 'latest_active_appointments_with_incomplete_schedule_or_assignment',COUNT(*) FROM dossier_fiche_rdv r JOIN dossier_fiches f ON f.id=r.dossier_fiche JOIN dossiers d ON d.id=r.dossier_id LEFT JOIN users u ON u.id=d.technicien_id WHERE f.status_id IN (2,10) AND d.status_id<>-1 AND NOT EXISTS(SELECT 1 FROM dossier_fiche_rdv newer WHERE newer.dossier_fiche=r.dossier_fiche AND newer.id>r.id) AND (r.date_rdv IS NULL OR r.date_rdv<'1000-01-01' OR r.heure IS NULL OR r.heure<'00:00:00' OR r.heure>='24:00:00' OR r.rdv_duree IS NULL OR r.rdv_duree<1 OR r.rdv_duree>24 OR u.id IS NULL OR u.type<>4)
    UNION ALL SELECT 'external_appointment_missing_technician',COUNT(*) FROM rdv_extern r LEFT JOIN users u ON u.id=r.technicien_id WHERE u.id IS NULL
    UNION ALL SELECT 'external_appointment_technician_not_field_profile',COUNT(*) FROM rdv_extern r JOIN users u ON u.id=r.technicien_id WHERE u.type<>4
    UNION ALL SELECT 'external_appointment_invalid_date_time_duration',COUNT(*) FROM rdv_extern WHERE date_rdv IS NULL OR date_rdv<'1000-01-01' OR heure IS NULL OR heure<'00:00:00' OR heure>='24:00:00' OR rdv_duree IS NULL OR rdv_duree<1 OR rdv_duree>24
) quality;

SELECT JSON_OBJECT('section','documents','metric',metric,'count',quantity) FROM (
    SELECT 'distinct_binary_paths' metric,COUNT(DISTINCT BINARY path) quantity FROM dossier_document
    UNION ALL SELECT 'empty_path',COUNT(*) FROM dossier_document WHERE TRIM(path)=''
    UNION ALL SELECT 'traversal_or_control_character_path',COUNT(*) FROM dossier_document WHERE INSTR(REPLACE(path,CHAR(92),'/'),'../')>0 OR path REGEXP '[[:cntrl:]]'
    UNION ALL SELECT 'absolute_or_url_path',COUNT(*) FROM dossier_document WHERE LEFT(REPLACE(path,CHAR(92),'/'),1)='/' OR INSTR(path,'://')>0
    UNION ALL SELECT 'duplicate_path_groups',COUNT(*) FROM (SELECT BINARY path FROM dossier_document GROUP BY BINARY path HAVING COUNT(*)>1) duplicates
    UNION ALL SELECT 'document_missing_author',COUNT(*) FROM dossier_document x LEFT JOIN users u ON u.id=x.author_id WHERE u.id IS NULL
    UNION ALL SELECT 'comment_missing_author',COUNT(*) FROM dossier_comment x LEFT JOIN users u ON u.id=x.author_id WHERE u.id IS NULL
    UNION ALL SELECT 'report_metadata_present',COUNT(*) FROM dossier_document WHERE reference_controle IS NOT NULL OR date_emission IS NOT NULL OR date_rdv IS NOT NULL
) documents;

SELECT JSON_OBJECT('section','document_audiences','private_flag',is_private,'delegataire_flag',is_delegataire,'rows',COUNT(*)) FROM dossier_document GROUP BY is_private,is_delegataire;
SELECT JSON_OBJECT('section','comment_audiences','private_flag',is_private,'rows',COUNT(*)) FROM dossier_comment GROUP BY is_private;

SELECT JSON_OBJECT('section','billing','metric',metric,'count',quantity) FROM (
    SELECT 'operations_currently_billed' metric,COUNT(*) quantity FROM dossier_fiches WHERE status_id=11
    UNION ALL SELECT 'dossiers_currently_billed',COUNT(DISTINCT dossier_id) FROM dossier_fiches WHERE status_id=11
    UNION ALL SELECT 'dossiers_with_billing_history',COUNT(DISTINCT dossier_id) FROM dossier_historique WHERE status_id=11
    UNION ALL SELECT 'billing_history_rows',COUNT(*) FROM dossier_historique WHERE status_id=11
    UNION ALL SELECT 'billing_history_missing_operation',COUNT(*) FROM dossier_historique h LEFT JOIN dossier_fiches f ON f.id=h.dossier_fiche_id WHERE h.status_id=11 AND f.id IS NULL
    UNION ALL SELECT 'billing_history_parent_mismatch',COUNT(*) FROM dossier_historique h JOIN dossier_fiches f ON f.id=h.dossier_fiche_id WHERE h.status_id=11 AND f.dossier_id<>h.dossier_id
    UNION ALL SELECT 'dossiers_with_bill_history_not_currently_billed',COUNT(DISTINCT h.dossier_id) FROM dossier_historique h WHERE h.status_id=11 AND NOT EXISTS(SELECT 1 FROM dossier_fiches f WHERE f.dossier_id=h.dossier_id AND f.status_id=11)
    UNION ALL SELECT 'dossiers_with_invoice_number',COUNT(*) FROM dossiers WHERE NULLIF(TRIM(numero_facture),'') IS NOT NULL
    UNION ALL SELECT 'accounting_marked_rows',COUNT(*) FROM dossier_fiche_facturation WHERE passer_facture=1
) billing;

SELECT JSON_OBJECT('section','catalogue','metric',metric,'count',quantity) FROM (
    SELECT 'duplicate_normalized_service_name_groups' metric,COUNT(*) quantity FROM (SELECT LOWER(TRIM(name)) FROM fiche_type GROUP BY LOWER(TRIM(name)) HAVING COUNT(*)>1) duplicates
    UNION ALL SELECT 'service_label_over_190',COUNT(*) FROM fiche_type WHERE CHAR_LENGTH(TRIM(name))>190
    UNION ALL SELECT 'blank_service_label',COUNT(*) FROM fiche_type WHERE TRIM(name)=''
    UNION ALL SELECT 'mail_template_utf32_columns',COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mail_templates' AND CHARACTER_SET_NAME='utf32'
    UNION ALL SELECT 'sms_template_utf32_columns',COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_tempalte' AND CHARACTER_SET_NAME='utf32'
) catalogue;
ROLLBACK;

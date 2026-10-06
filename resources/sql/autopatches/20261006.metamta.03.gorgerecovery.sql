ALTER TABLE {$NAMESPACE}_metamta.gorge_mail_delivery
 ADD deadline BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD projectionNextAttempt BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD projectionAttempts INT UNSIGNED NOT NULL DEFAULT 0,
 ADD projectionLastError VARCHAR(255) NOT NULL DEFAULT '',
 DROP KEY projectionPending,
 ADD KEY projectionPending (projectionPending,projectionNextAttempt,deliveryID),
 ADD KEY staleSubmission (state,startedEpoch,deliveryID),
 ADD KEY expiredDelivery (state,deadline,deliveryID);
UPDATE {$NAMESPACE}_metamta.gorge_mail_delivery
 SET deadline = CASE WHEN JSON_VALID(payload)
 THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.deadline')) AS UNSIGNED),0)
 ELSE 0 END;

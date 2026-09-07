ALTER TABLE {$NAMESPACE}_herald.herald_webhookrequest
  ADD KEY `key_status` (`status`, `id`);

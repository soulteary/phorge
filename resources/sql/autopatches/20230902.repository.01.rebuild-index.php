<?php

// This patch rebuilt the search index for tracked repositories. Repositories
// have been removed, so there is nothing to index and no query to rebuild
// from. The file stays because `bin/storage` tracks patches by name and an
// installation which already applied it must keep recognizing it.

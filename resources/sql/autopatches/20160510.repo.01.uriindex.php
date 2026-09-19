<?php

// This patch rebuilt the repository URI index by calling updateURIIndex() on
// every repository. Repositories have been removed, so there is nothing to
// index. The file stays because `bin/storage` tracks patches by name and an
// installation which already applied it must keep recognizing it.

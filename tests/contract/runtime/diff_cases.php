<?php

return array(
  'text-change' => "diff --git a/example.txt b/example.txt\n".
    "index 1111111..2222222 100644\n--- a/example.txt\n+++ b/example.txt\n".
    "@@ -1,2 +1,2 @@\n alpha\n-old\n+new\n",
  'empty-add' => "diff --git a/empty b/empty\nnew file mode 100644\n".
    "index 0000000..e69de29\n",
  'delete' => "diff --git a/remove.txt b/remove.txt\ndeleted file mode 100644\n".
    "index 1111111..0000000\n--- a/remove.txt\n+++ /dev/null\n".
    "@@ -1 +0,0 @@\n-removed\n",
  'rename' => "diff --git a/old.txt b/new.txt\nsimilarity index 100%\n".
    "rename from old.txt\nrename to new.txt\n",
  'unicode' => "--- old.txt\t1970-01-01 00:00:00\n".
    "+++ new.txt\t1970-01-01 00:00:00\n@@ -1 +1 @@\n-旧值\n+新值\n",
  'no-final-newline' => "--- old.txt\t1970-01-01 00:00:00\n".
    "+++ new.txt\t1970-01-01 00:00:00\n@@ -1 +1 @@\n".
    "-old\n\\ No newline at end of file\n+new\n\\ No newline at end of file\n",
  'binary' => "diff --git a/image.bin b/image.bin\n".
    "index 1111111..2222222 100644\n".
    "Binary files a/image.bin and b/image.bin differ\n",
  'svn-text' => "Index: example.txt\n".
    "===================================================================\n".
    "--- example.txt\t(revision 1)\n+++ example.txt\t(working copy)\n".
    "@@ -1 +1 @@\n-old\n+new\n",
);

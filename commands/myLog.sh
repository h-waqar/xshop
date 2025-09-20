#!/bin/bash
# mylogs - Show commits authored by the current git user

# If an argument is passed (branch name), show logs for that branch
BRANCH=${1:-HEAD}

git log $BRANCH --author="$(git config user.name)" \
  --pretty=format:"%C(yellow)%h%Creset %Cgreen%ad%Creset | %s" \
  --date=short

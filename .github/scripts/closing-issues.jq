# PR が閉じる issue(gh pr view --json closingIssuesReferences)のうち、このリポジトリ($repo = owner/repo)のものの番号
#
# 書いた人を確かめる issue と、Claude Code に読ませる issue を同じ集合にするため、選び方をここ 1 か所に置く
# (片方だけ変わると、確かめていない issue を読ませてしまう)

def own_closing_issues($repo):
  .closingIssuesReferences[] | select(.repository.owner.login + "/" + .repository.name == $repo) | .number;

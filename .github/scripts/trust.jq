# PR・issue の本文・コメント・レビューを書いた人を信頼するか(untrusted-comments.sh・trusted-context.sh が include する)
#
# 渡すのは REST の形(.user.login・.author_association・.body)。GraphQL は bot のログイン名に [bot] が付かないので渡さない。
#
# public のリポジトリでは誰でもコメントでき、Actions やルーチンの Claude Code はそれを読んで、書き込みのできる権限で動く。
# 外部の人がコメントで Claude Code に指示を渡し、自動マージとデプロイまで届かせられないよう、読ませるものをここで決める。
#
# - 信頼する: author_association が OWNER・MEMBER・COLLABORATOR の人と、このリポジトリに入れた決まった bot(trusted_bots)。
#   bot はアカウントの種類ではなくログイン名で決める(どの App を入れるかはオーナーが決めるが、種類だけでは見分けない)
# - bot のコメントでも、先頭がオーナー以外の人への宛名(@<login>)なら信頼しない。CodeRabbit は誰の問いかけにも返事をするので、
#   外部の人が自分の問いかけを消しても、bot の返事を通して指示を残せるため

def trusted_bots: ["coderabbitai[bot]", "github-actions[bot]", "claude[bot]", "github-advanced-security[bot]"];

def trusted($owner):
  def person: .author_association | IN("OWNER", "MEMBER", "COLLABORATOR");
  def bot: .user.login as $l | trusted_bots | index($l);
  # `capture(...)?.to` は jq 1.7 からの書き方で、クラウドのルーチンの jq 1.6 では構文エラーになる(#586)
  def addressed: .body // "" | ((capture("^\\s*(<!--[\\s\\S]*?-->\\s*)*@(?<to>[A-Za-z0-9-]+)") | .to)? // null)
    | . != null and . != $owner;
  person or (bot and (addressed | not));

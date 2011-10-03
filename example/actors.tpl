<html>
<head>
<title>My favourite actors</title>
</head>
<body>

<h1>Hello!</h1>

My favourite actors:
<ul>
{foreach $actors as $actor}
    <li>{$actor.name} (friendliness: {$actor.friendly})</li>
{foreachelse}
    <li><em>No cookie for you!</em></li>
{/foreach}
</ul>

</body>
</html>
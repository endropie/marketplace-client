<!DOCTYPE html>
<html>
    <head>
        <title>Marketplace Client</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script type="text/javascript">
            console.log("CHILD - Sending data to parent...", window.opener.MCJS);
            window.opener.MCJS.setLogged({
                id: "{{ $id }}",
                name: "{{ $name }}",
            });
            setTimeout(() => {
                window.close();
            }, 800);
        </script>
    </head>
    <body>&nbsp;</body>
</html>

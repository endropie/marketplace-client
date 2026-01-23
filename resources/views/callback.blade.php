<!DOCTYPE html>
<html>
    <head>
        <title>Marketplace Client</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script type="text/javascript">
            function sendDataToParent() {
                if (typeof window.onCloseWindowRegister === 'function') {
                    window.onCloseWindowRegister({message: "More data from child"});
                }
            }
            window.marketplacestore = {
                id: "{{ $id }}",
                name: "{{ $name }}",
            };
            console.log("CHILD - Closing window...");
            // window.close();
        </script>
    </head>
    <body>&nbsp;</body>
</html>

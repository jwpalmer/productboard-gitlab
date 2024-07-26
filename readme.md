## Gitlab / Productboard Sync

Welcome to my shitty code! This is meant to sync up Productboard and Gitlab until Productboard inevitably builds their own, much better native integration. By the time you finish reading through this, you'll have all fingers crossed that they integrate ASAP.


**This Code:**
- Pushes new features created in Productboard over to Gitlab when manually triggered via a custom field dropdown in Productboard (Not all PB features want to immediately go to Gitlab, that would be a lot more than will ever be actionable)
- If the custom field dropdown is updated *but* there's already a URL in the custom text field in the Productboard feature, then this script will move the Gitlab issue to the corresponding project from the dropdown values
- Updates any linked issue in Gitlab when it's changed in Productboard (title, description, state/status)
- Updates any linked issue in Productboard when it's changed in Gitlab (title, status)
- When the "push to Gitlab" dropdown's value is changed to "not set" and the "gitlab URL" field has a valid issue URL, deletes the corresponding linked feature from Gitlab


### Requirements/Setup
This isn't meant to be a long-term solution (though, I guess many things aren't and look what happens...), so this code is mostly written to be easily maintained, not replicated for other projects/companies/setups. Commenting the hell out of it just in case.

#### Host This Code Somewhere
Because this is just a quick script, there's no dependencies, frameworks, or databases. This code is just a gateway script to listen for, then translate and re-broadcast events from/to Gitlab and Productboard. Just drop this code on a server that runs PHP and point a public URL to the folder. The API keys for respective services are stored in config.php, make sure this isn't accessible to the web and you're all set.

#### Productboard
1. In the "integrations" section, under "Public API," create a new access token, and store it in config.php

2. Create two new custom fields in Productboard:
- "Push to Gitlab" - set as dropdown, and for the values, add your Gitlab project names with IDs in parentheses in the value (e.g., "My cool project (54637)")- these IDs are annoyingly string matched
- "Gitlab URL" - a text field, this is where the URL to the corresponding issue / product backlog item in Gitlab will go once a successful sync is established (and is how you know it worked)

3. Use the Productboard API to retrieve the IDs[1] of the two custom fields, and put them in their corresponding config variables in config.php
[1] see https://developer.productboard.com/#tag/hierarchyEntitiesCustomFields for instructions

4. Use the Productboard API to create a new subscription[2] to this script, appending /productboard as your route (e.g., https://api.example.com/my/project/path/productboard). This script will respond to the required Productboard validation at this route
[2] see https://developer.productboard.com/#operation/postWebhook for instructions


#### Gitlab
1. Gitlab requires a personal access token for a few key actions (group tokens won't work according to the API docs since an "author" needs to be assigned), so the first step is to log in, and under your own account, head to preferences, then "access tokens", and create a new one with "API" permissions to read/write to the Gitlab API for your project. Add the generated secret to the Gitlab keys array, and enter the Gitlab API URL (latest tested with this code is v4) to config.php

2. Generate a GUID to be passed to this application from Gitlab's webhooks in an X-GITLAB-TOKEN header, and pop that into config.php

3. In the Gitlab GUI, register a webhook with this project's URL, appended by /gitlab (e.g., https://api.example.com/my/project/path/gitlab) at {Group Name} > Settings > Webhooks, with Issue, Note, and Release events, over SSL


### Bringing it all together  
Once you've completed both steps above, you'll need to set the status mappings between Gitlab and Productboard, so that this code can translate between the two.

For Gitlab, the labels field is used, but in conjunction with state (opened/closed). For Productboard, you'll need to use the API to get the API IDs of the statuses you want, and then assign one to open, another to closed, and then map the rest you'd like to use one-to-one between Productboard and Gitlab.

Drop these into the STATUS_MAPPINGS array in config.php. This array does a lot of heavy lifting, so be sure to follow instructions in the comments around it.


That should be it! You're up and running. I've probably forgotten something, but let's be real– hopefully you're not really needing all of this. That'd mean you're setting this up from scratch, and that means this code is being used to do something it was never meant for. Godspeed if that's you.

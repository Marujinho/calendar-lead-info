# Task Scope

The users are ours sales reps, they have daily meetings with potential leads in google calendar.
The task is to build a system that goes to the google calendar api, and adds extra info about the leads, and emails each sales rep at 8:00 am:
- Full Name
- Company Name
- Email
- Linkedin profile 
- How many times he/she already had meetings with someone from our company. (In the last 30 days, if more than that do not add it)

We will not have more than 200 sales reps. But this system could be sold to other companies so we dont know how many sales reps could be in other companies, let's assume ~200 per company.

Separate the work into branches, so we can have a descent history of commits and can trace back decisions.

We also need integration tests and unit tests.




# Caveats

- I dont remember the data type that the api returns, so we'll need to improvise with what we know, also, you can do a search to figure this out. The only data i remember that come from the api is we pass the sales rep email and get all meetings he has had and will have in that week, and with whom, so the info about the lead will be here, we'll need to cross reference them to know how many times they have already been approached, unless YOU have a better idea.

(Use design pattern 'Strategy' for this, implement a function that will deliver the api data to the system, and when we I have the real service, I can simply add the real implementation and not everything else. So dependency inversion makes sense here.)

- Each Api call costs (in this scenario) so we need to take that into consideration, and minimize the api calls to the absolute necessary.
- Emails MUST be sent at 8:00 am daily, so any long running job canot mess this up.


**# Tech Stack**

* **Backend:** PHP with Laravel, powering both the application and data processing jobs
* **Databases:** MySQL
* **Messaging:** RabbitMQ, used for communication between microservices
* **Observability:** Datadog for logging and performance monitoring
* **Workflow:** GitHub
* **Infrastructure:** AWS








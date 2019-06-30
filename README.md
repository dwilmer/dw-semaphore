# dw_semaphore
A semaphore implementation for Wordpress to facilitate mutual exclusivity. See [wikipedia](https://en.wikipedia.org/wiki/Semaphore_(programming)) for more information on semaphores. [Repo on github](https://github.com/dwilmer/dw-semaphore).

## Basic Usage
1. Download and enable the plugin
2. Whenever there is an aread of code that requires mutual exclusivity (i.e. only one process/thread at a time should be able to run that piece of code), start that code using `$semaphore = DW_Semaphore::wait($name)`. When that code is finished, call `$semaphore->signal()` to signal that it's done.
3. Notes for performance:
   - All semaphores of the same name will wait for eachother. Only use the same name if you need all those semaphores to be mutually exclusive; use unique names where possible to avoid semaphores waiting on eachother needlessly.
   - Keep the time between `DW_Semaphore::wait()` and `$semaphore->signal` as short as possible, as other processes might be waiting. If you need a unique value and then do something with it, only make the generation of this unique value mutually exclusive and let the processing of that value be possible parallel.
4. Final note: while this plugin is designed to provide mutual exclusivity, semaphores can expire in case a process never `signal`s. The current handling does not ensure mutual exclusivity when semaphores expire. Since waiting times also count toward the expiration time, a long wait time can increase the risk of semaphores expiring and thus of race conditions occurring. Please be aware of this fact, and that no mutual exclusivity can thus be guaranteed using this plugin. It will only provide mutual exclusivity in favourable conditions, but under adverse conditions this can fail to allow for better performance (i.e. not locking your website due to one failed process).

## Bugs, improvements, suggestions, and other contributions
Please use the [repository on GitHub](https://github.com/dwilmer/dw-semaphore). You're welcome to create an [issue](https://github.com/dwilmer/dw-semaphore/issues) if you spot something that is wrong or could be better, and I'll appreciate all [pull requests](https://github.com/dwilmer/dw-semaphore/pulls).

Please note, though, that — although I will get notifications from any new activity — I might not get to answer your question or fix your issue or merge your pull request in the timeframe that you would want. I'll do my best, but no promises.
